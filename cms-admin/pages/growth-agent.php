<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

// Same tier as the rest of AI Management — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);

// Lazy auto-cleanup — no cron in this codebase (see sitemap-service.php's
// own note on that), so this runs quietly on every page load instead,
// same "self-maintaining on request" spirit as cms_ensure_table(). Only
// removes already-resolved jobs (failed, or succeeded-and-never-approved)
// older than 90 days; see cms_growth_agent_cleanup_old_jobs() for exactly
// what's protected from deletion. A manual "Bersihkan job lama" button
// further down runs the same function on demand with a chosen window.
cms_growth_agent_cleanup_old_jobs($pdo, 90);

$pageTitle = 'Growth Agent';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => ''],
];

$selfUrl = 'growth-agent.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    // ── Job review — feeds the Fase 3 few-shot pool ──────────────────────
    // Approving a job as-is is what makes it eligible as a future
    // GrowthAgentPromptBuilder example (see services/GrowthAgentPromptBuilder.php).
    if ($action === 'approve' || $action === 'reject') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            $redirect('Invalid job.', 'error');
        }

        $feedbackAction = $action === 'approve' ? 'approved_as_is' : 'rejected';
        $newStatus = $action === 'approve' ? 'succeeded' : 'failed';

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => $feedbackAction, 'reviewed_by' => $currentAdminId]);

        $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, updated_at = NOW() WHERE id = :id');
        $upd->execute(['status' => $newStatus, 'id' => $jobId]);

        $redirect($action === 'approve' ? 'Job approved — it may now be used as a future example.' : 'Job rejected.');
    }

    if ($action === 'style_rule_create') {
        $ruleText = trim((string) ($_POST['rule_text'] ?? ''));
        if ($ruleText === '') {
            $redirect('Style rule text is required.', 'error');
        }
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_style_rules (rule_text, source, is_active, created_by, created_at)
             VALUES (:rule_text, :source, 1, :created_by, NOW())'
        );
        $ins->execute(['rule_text' => $ruleText, 'source' => 'manual', 'created_by' => $currentAdminId]);
        $redirect('Style rule added.');
    }

    if ($action === 'style_rule_toggle') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Invalid rule.', 'error');
        }
        $pdo->prepare('UPDATE growth_agent_style_rules SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $ruleId]);
        $redirect('Style rule updated.');
    }

    if ($action === 'style_rule_delete') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Invalid rule.', 'error');
        }
        $pdo->prepare('DELETE FROM growth_agent_style_rules WHERE id = :id')->execute(['id' => $ruleId]);
        $redirect('Style rule removed.');
    }

    // ── "Apply SEO Recommendation" — manual scan trigger ─────────────────
    // Deliberately manual (a button click), not scheduled/automatic — see
    // the approved flow: Scan -> Resolve Target -> SEO child action ->
    // Review & Apply. Each scanned article becomes one manual_action job,
    // reviewed on seo-recommendation-review.php (not the generic
    // Approve/Reject buttons below), since "apply" here writes directly
    // into pages.meta_title / pages.meta_description.
    if ($action === 'scan_seo') {
        $scanStats = cms_growth_agent_scan_seo_recommendations($pdo, 5);
        if ($scanStats['created'] > 0) {
            $redirect($scanStats['created'] . ' rekomendasi SEO baru dibuat dari ' . $scanStats['scanned'] . ' artikel yang di-scan. Cek di tabel "Recent jobs" di bawah.');
        }
        if ($scanStats['scanned'] === 0) {
            $redirect('Tidak ada artikel baru untuk di-scan — semua artikel published sudah pernah di-scan.', 'error');
        }
        $redirect('Scan selesai (' . $scanStats['scanned'] . ' artikel) tapi tidak ada rekomendasi yang berhasil dibuat. Coba lagi nanti.', 'error');
    }

    // ── Manual cleanup — same rules as the lazy auto-cleanup above, just
    // on-demand with a chosen retention window. Never removes 'ready',
    // 'running', or 'manual_action' jobs, and never removes a 'succeeded'
    // job a human approved as-is (the Fase 3 few-shot pool).
    if ($action === 'cleanup_jobs') {
        $days = (int) ($_POST['days'] ?? 90);
        $deleted = cms_growth_agent_cleanup_old_jobs($pdo, $days);
        $redirect($deleted > 0
            ? $deleted . ' job lama (lebih dari ' . max(7, min(365, $days)) . ' hari) berhasil dihapus.'
            : 'Tidak ada job yang perlu dibersihkan untuk jendela waktu itu.');
    }

    $redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

// ── Stats ─────────────────────────────────────────────────────────────
// NOTE: this connection is opened with PDO::ATTR_DEFAULT_FETCH_MODE =>
// PDO::FETCH_ASSOC (see config/database.php) — a plain fetch() here never
// has a numeric index [0], so the previous "$row[0] ?? 0" always silently
// fell through to 0 regardless of the real count. Fixed by aliasing the
// column and reading it by name.
$safeCount = static function (PDO $pdo, string $sql): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row['cnt'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
};

$statsCards = [
    ['label' => 'Approved / Ready', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'ready'"), 'hint' => 'Awaiting execute'],
    ['label' => 'Running', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'running'"), 'hint' => 'In progress'],
    ['label' => 'Succeeded', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'succeeded'"), 'hint' => 'Draft created'],
    ['label' => 'Failed', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'failed'"), 'hint' => 'Retryable'],
    ['label' => 'Manual Actions', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'manual_action'"), 'hint' => 'Operator execution required'],
];

// ── Recent jobs — with page title (if linked) and whether feedback already exists ──
$jobsStmt = $pdo->query(
    "SELECT j.id, j.job_type, j.agent_key, j.page_id, j.status, j.model_used, j.latency_ms,
            j.error_message, j.created_at, p.title AS page_title,
            (SELECT COUNT(*) FROM growth_agent_feedback f WHERE f.job_id = j.id) AS feedback_count
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      ORDER BY j.created_at DESC
      LIMIT 25"
);
$jobs = $jobsStmt->fetchAll();

// ── Style rules ──────────────────────────────────────────────────────
$rulesStmt = $pdo->query(
    'SELECT id, rule_text, source, is_active, created_at FROM growth_agent_style_rules ORDER BY created_at DESC'
);
$styleRules = $rulesStmt->fetchAll();

$statusPill = [
    'ready' => 'muted',
    'running' => 'accent',
    'succeeded' => 'ok',
    'failed' => 'warn',
    'manual_action' => 'info',
];

// Scopes the panel text/spacing CSS fix in admin.css to this page only —
// see .page-growth-agent rules there.
$bodyClass = 'page-growth-agent';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Growth Agent</h2>
            <p class="section-lead">SEO &amp; content pipeline — drafts, runs, and hands work back for approval.</p>
        </div>
        <div class="toolbar__right">
            <form method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="scan_seo">
                <button type="submit" class="admin-btn admin-btn--primary">Scan for SEO improvements</button>
            </form>
        </div>
    </div>
    <p class="section-lead" style="margin-top:-8px;">Scan checks published articles that haven't been scanned yet (up to 5 per click) and proposes an improved meta title/description for each — nothing is changed until you review and apply it.</p>

    <div class="admin-grid admin-grid--stats">
        <?php foreach ($statsCards as $card) : ?>
            <article class="stat-card">
                <div class="stat-card__label"><?= cms_esc($card['label']) ?></div>
                <div class="stat-card__value"><?= cms_esc((string) $card['value']) ?></div>
                <div class="stat-card__hint"><?= cms_esc($card['hint']) ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Recent jobs</h3>
            <span class="panel__meta"><?= count($jobs) ?> shown</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Article</th>
                        <th>Status</th>
                        <th>Model</th>
                        <th>Latency</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($jobs === []) : ?>
                        <tr><td colspan="7" class="muted">No jobs yet — they'll show up here every time Generate SEO (or a future Growth Agent job type) runs.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($jobs as $job) : ?>
                        <?php
                        $pill = $statusPill[$job['status']] ?? 'muted';
                        $isSeoRecommendation = $job['job_type'] === 'seo_recommendation';
                        // seo_recommendation jobs get their own review page (Apply
                        // writes straight into pages.meta_title/meta_description),
                        // so they never use the generic Approve/Reject buttons below.
                        $canReviewGeneric = !$isSeoRecommendation && (int) $job['feedback_count'] === 0 && in_array($job['status'], ['succeeded', 'failed', 'manual_action'], true);
                        $canReviewSeo = $isSeoRecommendation && $job['status'] === 'manual_action';
                        ?>
                        <tr>
                            <td>
                                <strong><?= cms_esc((string) $job['job_type']) ?></strong><br>
                                <span class="muted">agent: <code><?= cms_esc((string) $job['agent_key']) ?></code></span>
                            </td>
                            <td><?= $job['page_title'] ? cms_esc((string) $job['page_title']) : '<span class="muted">—</span>' ?></td>
                            <td>
                                <span class="pill pill--<?= $pill ?>"><?= cms_esc((string) $job['status']) ?></span>
                                <?php if ($job['status'] === 'failed' && $job['error_message']) : ?>
                                    <div class="muted" style="font-size:11px;margin-top:4px;max-width:220px;"><?= cms_esc(mb_substr((string) $job['error_message'], 0, 140)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $job['model_used'] ? cms_esc((string) $job['model_used']) : '<span class="muted">—</span>' ?></td>
                            <td><?= $job['latency_ms'] !== null ? cms_esc((string) $job['latency_ms']) . ' ms' : '<span class="muted">—</span>' ?></td>
                            <td class="muted"><?= cms_esc((string) $job['created_at']) ?></td>
                            <td class="table-actions">
                                <?php if ($canReviewSeo) : ?>
                                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="seo-recommendation-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                                <?php elseif ($canReviewGeneric) : ?>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Approve</button>
                                    </form>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Reject</button>
                                    </form>
                                <?php else : ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Style rules</h3>
            <span class="panel__meta"><?= count($styleRules) ?> item(s)</span>
        </div>
        <p class="section-lead">Active rules are folded into every generate call — see Fase 3 in the GrowthAgent architecture doc.</p>

        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:16px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="style_rule_create">
            <textarea name="rule_text" rows="2" placeholder="e.g. Always write meta_title in Bahasa Indonesia, never use clickbait phrasing." style="flex:1;" required></textarea>
            <button type="submit" class="admin-btn admin-btn--primary">Add rule</button>
        </form>

        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($styleRules === []) : ?>
                        <tr><td colspan="4" class="muted">No style rules yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($styleRules as $rule) : ?>
                        <tr>
                            <td><?= cms_esc((string) $rule['rule_text']) ?></td>
                            <td><span class="muted"><?= cms_esc((string) $rule['source']) ?></span></td>
                            <td>
                                <?php if ((int) $rule['is_active'] === 1) : ?>
                                    <span class="pill pill--ok">Active</span>
                                <?php else : ?>
                                    <span class="pill pill--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_toggle">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary"><?= (int) $rule['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Remove this style rule?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_delete">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Maintenance</h3>
        </div>
        <p class="section-lead">
            Job lama otomatis dibersihkan setiap halaman ini dibuka (job yang sudah selesai &amp; berumur
            &gt; 90 hari). Job berstatus <strong>Manual Actions</strong> (belum di-review) dan job yang sudah
            di-Approve sebagai contoh (few-shot) tidak pernah dihapus otomatis maupun manual.
        </p>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Hapus job selesai/gagal yang lebih tua dari jumlah hari ini? Job yang masih menunggu review tidak akan terhapus.');">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="cleanup_jobs">
            <label class="muted" style="font-size:13px;">Hapus job selesai/gagal yang lebih tua dari</label>
            <input type="number" name="days" value="30" min="7" max="365" style="width:80px;">
            <span class="muted" style="font-size:13px;">hari</span>
            <button type="submit" class="admin-btn admin-btn--ghost">Bersihkan sekarang</button>
        </form>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
