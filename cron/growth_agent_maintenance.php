#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: run Growth Agent's eight maintenance/collection steps on a
 * schedule, instead of only "lazily" whenever an admin happens to open
 * cms-admin/pages/growth-agent.php.
 *
 * Thin CLI wrapper — no logic lives here. It calls the exact same shared
 * functions (with the exact same parameters) as growth-agent.php's page
 * load: cms_growth_agent_ensure_schema(), cms_growth_agent_cleanup_old_jobs(),
 * cms_gsc_fetch_if_stale(), cms_growth_agent_detect_memory_if_stale(),
 * cms_growth_agent_snapshot_performance_if_stale(),
 * cms_growth_agent_run_measurement_loop(),
 * cms_growth_agent_refresh_trending_headlines_if_stale() — all in
 * cms-admin/includes/growth-agent-service.php and gsc-api.php. Both callers
 * run identical code, same as sync_fixtures.php vs the admin "Sync Sekarang"
 * button.
 *
 * The lazy page-load calls in growth-agent.php are NOT removed by this
 * script's existence — they stay as a safety net for whenever this cron
 * hasn't run yet or is misconfigured. The two are meant to coexist.
 *
 * No script-level kill-switch based on GSC's on/off state: unlike
 * cms_gsc_fetch_if_stale() (which already no-ops internally when GSC isn't
 * connected/active — see gsc-api.php's own is_active/site_url guard),
 * ensure_schema and cleanup_old_jobs don't depend on GSC at all. Gating the
 * whole script on GSC being active would wrongly skip schema upkeep and job
 * cleanup on installs that don't use GSC yet.
 *
 *   php cron/growth_agent_maintenance.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/growth-agent-service.php';
require_once __DIR__ . '/../cms-admin/includes/gsc-api.php';

$exitCode = 0;

// ── 1. Schema upkeep ────────────────────────────────────────────────────
// Not documented "never throws" like the four functions below (it calls
// cms_ensure_table(), which has no internal try/catch), so it's the one
// step wrapped here rather than trusted bare — a schema hiccup shouldn't
// abort the rest of this script, but it IS a real failure worth a non-zero
// exit so cPanel's cron failure email actually fires.
try {
    cms_growth_agent_ensure_schema($pdo);
    cms_gsc_ensure_schema($pdo);
    echo "[growth_agent_maintenance] ensure_schema: OK.\n";
} catch (Throwable $e) {
    echo "[growth_agent_maintenance] ensure_schema: FAILED — {$e->getMessage()}\n";
    $exitCode = 1;
}

// ── 2. Cleanup old jobs (90 days, same window as the lazy call) ────────
// Documented "never throws" — returns 0 on internal failure instead.
$deleted = cms_growth_agent_cleanup_old_jobs($pdo, 90);
echo "[growth_agent_maintenance] cleanup_old_jobs: {$deleted} job(s) deleted (retention 90 hari).\n";

// ── 3–5. The three *_if_stale() steps ───────────────────────────────────
// These return void, so the only way to report "did it actually run or was
// it skipped" is reading gsc_settings' own timestamp columns before/after —
// cms_gsc_fetch_if_stale() etc. only touch them when they actually ran.
//
// The before/after timestamp diff alone can't tell "skipped, not stale yet"
// apart from "attempted because it WAS stale, but failed silently inside"
// (e.g. the Google API being unreachable) — both leave the timestamp
// unchanged, since these functions never throw and only write the
// timestamp on success. So staleness is also computed here independently,
// using the same formula as the underlying functions, to report which of
// those two actually happened instead of guessing.
$isStale = static function (?string $lastRun, int $maxAgeHours): bool {
    return $lastRun === null || (time() - strtotime($lastRun)) >= ($maxAgeHours * 3600);
};

$before = cms_gsc_get_settings($pdo);
$wasConfigured = (int) ($before['is_active'] ?? 0) === 1 && !empty($before['site_url'] ?? null);
$wasStale = $isStale($before['last_fetch_at'] ?? null, 24);

cms_gsc_fetch_if_stale($pdo, 24);
$after = cms_gsc_get_settings($pdo);
if (!$wasConfigured) {
    echo "[growth_agent_maintenance] gsc_fetch: Skipped — GSC belum dikonfigurasi/nonaktif (gsc_settings.is_active != 1 atau site_url kosong).\n";
} elseif (($after['last_fetch_at'] ?? null) !== ($before['last_fetch_at'] ?? null)) {
    echo "[growth_agent_maintenance] gsc_fetch: Ran — last_fetch_at diperbarui ke {$after['last_fetch_at']}.\n";
} elseif ($wasStale) {
    echo "[growth_agent_maintenance] gsc_fetch: Dicoba (data stale, last_fetch_at: " . ($before['last_fetch_at'] ?? 'null') . ") tapi last_fetch_at tidak berubah — kemungkinan gagal diam-diam di dalam (mis. Google API tidak terjangkau). Cek PHP error log.\n";
} else {
    echo "[growth_agent_maintenance] gsc_fetch: Skipped — belum stale (last_fetch_at: " . ($before['last_fetch_at'] ?? 'null') . ", ambang 24 jam).\n";
}

$before = $after;
$wasStale = $isStale($before['last_memory_detection_at'] ?? null, 24 * (int) (cms_gsc_get_memory_thresholds($pdo)['detection_interval_days'] ?? 1));
cms_growth_agent_detect_memory_if_stale($pdo);
$after = cms_gsc_get_settings($pdo);
if (($after['last_memory_detection_at'] ?? null) !== ($before['last_memory_detection_at'] ?? null)) {
    echo "[growth_agent_maintenance] memory_detect: Ran — last_memory_detection_at diperbarui ke {$after['last_memory_detection_at']}.\n";
} elseif ($wasStale) {
    echo "[growth_agent_maintenance] memory_detect: Dicoba (data stale, last_memory_detection_at: " . ($before['last_memory_detection_at'] ?? 'null') . ") tapi timestamp tidak berubah — kemungkinan gagal diam-diam di dalam. Cek PHP error log.\n";
} else {
    echo "[growth_agent_maintenance] memory_detect: Skipped — belum stale (last_memory_detection_at: " . ($before['last_memory_detection_at'] ?? 'null') . ").\n";
}

$before = $after;
$wasStale = $isStale($before['last_performance_snapshot_at'] ?? null, 24);
cms_growth_agent_snapshot_performance_if_stale($pdo, 24);
$after = cms_gsc_get_settings($pdo);
if (($after['last_performance_snapshot_at'] ?? null) !== ($before['last_performance_snapshot_at'] ?? null)) {
    echo "[growth_agent_maintenance] perf_snapshot: Ran — last_performance_snapshot_at diperbarui ke {$after['last_performance_snapshot_at']}.\n";
} elseif ($wasStale) {
    echo "[growth_agent_maintenance] perf_snapshot: Dicoba (data stale, last_performance_snapshot_at: " . ($before['last_performance_snapshot_at'] ?? 'null') . ") tapi timestamp tidak berubah — kemungkinan gagal diam-diam di dalam. Cek PHP error log.\n";
} else {
    echo "[growth_agent_maintenance] perf_snapshot: Skipped — belum stale (last_performance_snapshot_at: " . ($before['last_performance_snapshot_at'] ?? 'null') . ", ambang 24 jam).\n";
}

// ── 6. Measurement Loop (Fase C, reprioritized ahead of Fase E) ────────
// Not a *_if_stale() function — no "last run" timestamp to diff, its own
// WHERE clause (measured_at IS NULL AND N+ days old) is what makes repeat
// calls safe/cheap, so it just returns its own stats directly instead.
// Documented "never throws" — returns zeroed stats on internal failure.
$measurement = cms_growth_agent_run_measurement_loop($pdo);
echo "[growth_agent_maintenance] measurement_loop: {$measurement['checked']} job dicek, "
    . "{$measurement['measured']} ditandai measured_at ({$measurement['insufficient_data']} di antaranya insufficient_data), "
    . "{$measurement['errors']} error (measured_at dibiarkan kosong, dicoba lagi run berikutnya).\n";

// ── 7. Trending Headlines refresh (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug
// 2026) ── Back to the *_if_stale() before/after-timestamp reporting shape
// (steps 3-5) — this one IS gated by a "last run" timestamp
// (last_trending_headlines_refresh_at), unlike step 6.
$before = cms_gsc_get_settings($pdo);
$trendingConfig = cms_gsc_get_opportunity_thresholds($pdo)['trending_headlines'] ?? [];
$wasStale = $isStale($before['last_trending_headlines_refresh_at'] ?? null, max(1, (int) ($trendingConfig['refresh_interval_hours'] ?? 12)));

cms_growth_agent_refresh_trending_headlines_if_stale($pdo);
$after = cms_gsc_get_settings($pdo);
if (($after['last_trending_headlines_refresh_at'] ?? null) !== ($before['last_trending_headlines_refresh_at'] ?? null)) {
    echo "[growth_agent_maintenance] trending_headlines: Ran — last_trending_headlines_refresh_at diperbarui ke {$after['last_trending_headlines_refresh_at']}.\n";
} elseif ($wasStale) {
    echo "[growth_agent_maintenance] trending_headlines: Dicoba (data stale) tapi timestamp tidak berubah — kemungkinan semua sumber gagal diambil (situs down/struktur berubah). Cek PHP error log.\n";
} else {
    echo "[growth_agent_maintenance] trending_headlines: Skipped — belum stale (last_trending_headlines_refresh_at: " . ($before['last_trending_headlines_refresh_at'] ?? 'null') . ").\n";
}

// ── 8. Full Draft Automation (GROWTH_AGENT_V2_PROPOSAL.md § 6, Fase F/H,
// 8 Aug 2026) ── The ONLY trigger for
// cms_growth_agent_generate_auto_draft_article() anywhere in this
// codebase — deliberately not a lazy page-load call like steps 3-7 above,
// since this one makes a real paid AI call plus an image-generation call
// (see cms_growth_agent_maybe_generate_auto_draft()'s own docblock).
// Ships disabled (auto_draft_automation.enabled=false) — always a clean
// no-op until an operator turns it on from the Agent & Setelan panel.
$autoDraftResult = cms_growth_agent_maybe_generate_auto_draft($pdo);
if ($autoDraftResult['ran']) {
    echo "[growth_agent_maintenance] auto_draft_article: {$autoDraftResult['reason']}"
        . ($autoDraftResult['job_id'] > 0 ? " (job_id={$autoDraftResult['job_id']})" : '') . ".\n";
} else {
    echo "[growth_agent_maintenance] auto_draft_article: Skipped — {$autoDraftResult['reason']}.\n";
}

echo "[growth_agent_maintenance] Done.\n";
exit($exitCode);
