<?php
declare(strict_types=1);

/**
 * Config loader for the Livescore/API-Football integration — backed by
 * the unified `sports_api_settings` table (sport_key = 'football'), one
 * row among all sports (24 Jul 2026 consolidation; used to be its own
 * `livescore_api_settings` singleton table, same pattern as
 * BasketballSettings.php/FormulaOneSettings.php). Shared by the admin UI
 * (cms-admin/pages/livescore-api-settings.php), the test-connection
 * action, and the standalone cron scripts under cron/ — anything that
 * needs the API key or sync config goes through here instead of reading
 * the table directly, so the decrypt + tracked-league-parsing logic
 * lives in exactly one place. Public method shapes are unchanged from
 * before the consolidation, so callers needed no changes.
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';
require_once __DIR__ . '/SportsApiSettings.php';

final class LivescoreSettings
{
    private const SPORT_KEY = 'football';

    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, tracked_league_ids: list<int>,
     *   sync_fixtures_interval: int, sync_secondary_interval: ?int, cache_duration_live: int,
     *   is_active: bool, auto_sync_enabled: bool,
     *   nav_placement: string, sort_order: int,
     *   last_primary_sync_at: ?string, last_secondary_sync_at: ?string,
     *   last_test_status: ?string, last_test_message: ?string, last_test_at: ?string
     * }
     */
    public static function load(PDO $pdo): array
    {
        wpm_ensure_sports_api_settings_table($pdo);

        $stmt = $pdo->prepare('SELECT * FROM sports_api_settings WHERE sport_key = :key LIMIT 1');
        $stmt->execute(['key' => self::SPORT_KEY]);
        $row = $stmt->fetch();

        $apiKeyEnc = (string) ($row['api_key_enc'] ?? '');

        return [
            'id' => (int) $row['sport_id'],
            'provider' => (string) $row['provider'],
            'base_url' => rtrim((string) $row['base_url'], '/'),
            'api_key' => $apiKeyEnc !== '' ? cms_ai_decrypt($apiKeyEnc) : '',
            'api_key_header' => (string) $row['api_key_header'],
            'tracked_league_ids' => self::parseLeagueIds((string) ($row['tracked_ids'] ?? '')),
            'sync_fixtures_interval' => (int) $row['sync_primary_interval'],
            'sync_secondary_interval' => $row['sync_secondary_interval'] !== null ? (int) $row['sync_secondary_interval'] : null,
            'cache_duration_live' => (int) $row['cache_duration_live'],
            'is_active' => (int) $row['is_active'] === 1,
            'auto_sync_enabled' => (int) $row['auto_sync_enabled'] === 1,
            'nav_placement' => (string) $row['nav_placement'],
            'page_title' => $row['page_title'] !== null ? (string) $row['page_title'] : null,
            'page_subtitle' => $row['page_subtitle'] !== null ? (string) $row['page_subtitle'] : null,
            'sort_order' => (int) $row['sort_order'],
            'last_primary_sync_at' => $row['last_primary_sync_at'] !== null ? (string) $row['last_primary_sync_at'] : null,
            'last_secondary_sync_at' => $row['last_secondary_sync_at'] !== null ? (string) $row['last_secondary_sync_at'] : null,
            'last_test_status' => $row['last_test_status'] !== null ? (string) $row['last_test_status'] : null,
            'last_test_message' => $row['last_test_message'] !== null ? (string) $row['last_test_message'] : null,
            'last_test_at' => $row['last_test_at'] !== null ? (string) $row['last_test_at'] : null,
        ];
    }

    /**
     * Throttle gate for infrequent syncs (leagues/teams metadata — roster
     * changes rarely, no reason to re-fetch on every cron tick). Reads
     * sync_secondary_interval from the DB (falls back to
     * sync_fixtures_interval if secondary was never configured) instead of
     * a hardcoded number, so the admin form's field is what actually
     * controls this. $force=true (manual "Sync Sekarang") always bypasses.
     */
    public static function secondarySyncDue(PDO $pdo, bool $force = false): bool
    {
        if ($force) {
            return true;
        }
        $settings = self::load($pdo);
        if ($settings['last_secondary_sync_at'] === null) {
            return true;
        }
        $intervalSeconds = $settings['sync_secondary_interval'] ?? $settings['sync_fixtures_interval'];
        return (time() - strtotime($settings['last_secondary_sync_at'])) >= $intervalSeconds;
    }

    /** Stamps last_secondary_sync_at = now — call after a successful throttled sync (see secondarySyncDue()). */
    public static function markSecondarySynced(PDO $pdo): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare('UPDATE sports_api_settings SET last_secondary_sync_at = NOW() WHERE sport_key = :key')
            ->execute(['key' => self::SPORT_KEY]);
    }

    /**
     * Throttle gate for the non-live fixtures pass (today/tomorrow date
     * fetch in wpm_sync_fixtures() — 2 API calls, upcoming/finished
     * matches that don't need refreshing every cron tick). Reads
     * sync_primary_interval from the DB (the "Interval sync fixtures
     * (menit)" admin field — existed since the first migration but never
     * actually enforced anywhere until this fix) instead of a hardcoded
     * number. The live pass is NOT gated by this — it always runs, since
     * that's the one pass that genuinely needs to be fresh.
     */
    public static function primarySyncDue(PDO $pdo, bool $force = false): bool
    {
        if ($force) {
            return true;
        }
        $settings = self::load($pdo);
        if ($settings['last_primary_sync_at'] === null) {
            return true;
        }
        return (time() - strtotime($settings['last_primary_sync_at'])) >= $settings['sync_fixtures_interval'];
    }

    /** Stamps last_primary_sync_at = now — call after a successful throttled non-live fixtures pass (see primarySyncDue()). */
    public static function markPrimarySynced(PDO $pdo): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare('UPDATE sports_api_settings SET last_primary_sync_at = NOW() WHERE sport_key = :key')
            ->execute(['key' => self::SPORT_KEY]);
    }

    /**
     * Records a cron sync failure into the same last_test_* columns Test
     * Connection uses (24 Jul 2026 quota-exhaustion fix) — so a cron run
     * that failed because the daily API quota is exhausted shows up in
     * the admin UI without needing a manual Test Connection click first.
     * Callers should only call this for failures worth surfacing (e.g.
     * quota/rate-limit errors), not routine transient network blips.
     */
    public static function recordSyncFailure(PDO $pdo, string $message): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare(
            "UPDATE sports_api_settings SET last_test_status = 'failed', last_test_message = :message, last_test_at = NOW() WHERE sport_key = :key"
        )->execute(['message' => $message, 'key' => self::SPORT_KEY]);
    }

    /**
     * Counterpart to recordSyncFailure() (25 Jul 2026 fix) — without this,
     * last_test_status only ever moved failed -> failed (overwritten by
     * the next failure) or stayed stuck on "failed" forever after a single
     * quota blip, even once later syncs/Test Connection clearly succeeded,
     * because nothing ever wrote 'success' back except a manual Test
     * Connection click. Call at the end of a sync run that had no quota
     * error, so the admin badge clears itself automatically instead of
     * showing a stale "quota habis" alert indefinitely.
     */
    public static function recordSyncSuccess(PDO $pdo, string $message): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare(
            "UPDATE sports_api_settings SET last_test_status = 'success', last_test_message = :message, last_test_at = NOW() WHERE sport_key = :key"
        )->execute(['message' => $message, 'key' => self::SPORT_KEY]);
    }

    /** True only when the feature is on AND the cron scripts are allowed to run. Gates cron only — manual "Sync Sekarang" uses isActive() so an admin can test before flipping auto-sync on. */
    public static function autoSyncAllowed(PDO $pdo): bool
    {
        $settings = self::load($pdo);
        return $settings['is_active'] && $settings['auto_sync_enabled'];
    }

    /** True when the feature itself is on, regardless of auto_sync_enabled — what manual sync should check. */
    public static function isActive(PDO $pdo): bool
    {
        return self::load($pdo)['is_active'];
    }

    /**
     * The date range API-Football's current plan will actually serve for
     * /fixtures?date= (free plans only allow a rolling few-day window —
     * see ApiFootballClient::parseDateWindowError()). Learned lazily by
     * cron/sync_fixtures.php (throttled probe), not by any live page
     * request — football.php only ever reads this cached value so a
     * user picking an arbitrary date from the calendar never triggers an
     * outbound API call itself.
     *
     * @return array{start: ?string, end: ?string, checked_at: ?string}
     */
    public static function getApiDateWindow(PDO $pdo): array
    {
        wpm_ensure_sports_api_settings_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT date_window_start, date_window_end, date_window_checked_at
             FROM sports_api_settings WHERE sport_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => self::SPORT_KEY]);
        $row = $stmt->fetch();

        return [
            'start' => $row['date_window_start'] !== null ? (string) $row['date_window_start'] : null,
            'end' => $row['date_window_end'] !== null ? (string) $row['date_window_end'] : null,
            'checked_at' => $row['date_window_checked_at'] !== null ? (string) $row['date_window_checked_at'] : null,
        ];
    }

    /** Persists a freshly-learned (or newly-cleared, when null/null) date window. Always stamps checked_at = now. */
    public static function saveApiDateWindow(PDO $pdo, ?string $start, ?string $end): void
    {
        wpm_ensure_sports_api_settings_table($pdo);

        $stmt = $pdo->prepare(
            'UPDATE sports_api_settings
             SET date_window_start = :start, date_window_end = :end, date_window_checked_at = NOW()
             WHERE sport_key = :key'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'key' => self::SPORT_KEY]);
    }

    /** @return list<int> */
    public static function parseLeagueIds(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }
        $ids = array_map(static fn (string $v): int => (int) trim($v), explode(',', $csv));
        return array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
    }

    /** @param list<int> $ids */
    public static function formatLeagueIds(array $ids): string
    {
        return implode(',', array_values(array_unique(array_filter($ids, static fn ($v) => (int) $v > 0))));
    }
}
