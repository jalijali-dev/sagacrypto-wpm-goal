<?php
declare(strict_types=1);

/**
 * Single-row config loader for the Livescore/API-Football integration
 * (table: livescore_api_settings, id=1 — a "singleton settings row"
 * pattern, same as basketball_api_settings/f1_api_settings). Shared by the admin UI
 * (cms-admin/pages/livescore-api-settings.php), the test-connection
 * action, and the standalone cron scripts under cron/ — anything that
 * needs the API key or sync config goes through here instead of reading
 * the table directly, so the decrypt + tracked-league-parsing logic
 * lives in exactly one place.
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';

final class LivescoreSettings
{
    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, tracked_league_ids: list<int>,
     *   sync_fixtures_interval: int, cache_duration_live: int,
     *   is_active: bool, auto_sync_enabled: bool,
     *   last_test_status: ?string, last_test_message: ?string, last_test_at: ?string
     * }
     */
    public static function load(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $row = $pdo->query('SELECT * FROM livescore_api_settings WHERE id = 1 LIMIT 1')->fetch();
        if ($row === false) {
            $pdo->exec('INSERT IGNORE INTO livescore_api_settings (id) VALUES (1)');
            $row = $pdo->query('SELECT * FROM livescore_api_settings WHERE id = 1 LIMIT 1')->fetch();
        }

        $apiKeyEnc = (string) ($row['api_key_enc'] ?? '');

        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'base_url' => rtrim((string) $row['base_url'], '/'),
            'api_key' => $apiKeyEnc !== '' ? cms_ai_decrypt($apiKeyEnc) : '',
            'api_key_header' => (string) $row['api_key_header'],
            'tracked_league_ids' => self::parseLeagueIds((string) ($row['tracked_league_ids'] ?? '')),
            'sync_fixtures_interval' => (int) $row['sync_fixtures_interval'],
            'cache_duration_live' => (int) $row['cache_duration_live'],
            'is_active' => (int) $row['is_active'] === 1,
            'auto_sync_enabled' => (int) $row['auto_sync_enabled'] === 1,
            'last_test_status' => $row['last_test_status'] !== null ? (string) $row['last_test_status'] : null,
            'last_test_message' => $row['last_test_message'] !== null ? (string) $row['last_test_message'] : null,
            'last_test_at' => $row['last_test_at'] !== null ? (string) $row['last_test_at'] : null,
        ];
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
     * request — livescore.php only ever reads this cached value so a
     * user picking an arbitrary date from the calendar never triggers an
     * outbound API call itself.
     *
     * @return array{start: ?string, end: ?string, checked_at: ?string}
     */
    public static function getApiDateWindow(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $row = $pdo->query(
            'SELECT fixture_date_window_start, fixture_date_window_end, fixture_date_window_checked_at
             FROM livescore_api_settings WHERE id = 1 LIMIT 1'
        )->fetch();

        return [
            'start' => $row['fixture_date_window_start'] !== null ? (string) $row['fixture_date_window_start'] : null,
            'end' => $row['fixture_date_window_end'] !== null ? (string) $row['fixture_date_window_end'] : null,
            'checked_at' => $row['fixture_date_window_checked_at'] !== null ? (string) $row['fixture_date_window_checked_at'] : null,
        ];
    }

    /** Persists a freshly-learned (or newly-cleared, when null/null) date window. Always stamps checked_at = now. */
    public static function saveApiDateWindow(PDO $pdo, ?string $start, ?string $end): void
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'UPDATE livescore_api_settings
             SET fixture_date_window_start = :start, fixture_date_window_end = :end, fixture_date_window_checked_at = NOW()
             WHERE id = 1'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);
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

    private static function ensureSchema(PDO $pdo): void
    {
        cms_ensure_table(
            $pdo,
            'livescore_api_settings',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             provider VARCHAR(100) NOT NULL DEFAULT \'API-Football\',
             base_url VARCHAR(255) NOT NULL DEFAULT \'https://v3.football.api-sports.io\',
             api_key_enc VARCHAR(255) DEFAULT NULL,
             api_key_header VARCHAR(100) NOT NULL DEFAULT \'x-apisports-key\',
             tracked_league_ids VARCHAR(255) DEFAULT NULL,
             sync_fixtures_interval INT UNSIGNED NOT NULL DEFAULT 300,
             sync_standings_interval INT UNSIGNED NOT NULL DEFAULT 3600,
             cache_duration_live INT UNSIGNED NOT NULL DEFAULT 60,
             cache_duration_standings INT UNSIGNED NOT NULL DEFAULT 3600,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
             last_test_status VARCHAR(20) DEFAULT NULL,
             last_test_message VARCHAR(255) DEFAULT NULL,
             last_test_at TIMESTAMP NULL DEFAULT NULL,
             fixture_date_window_start DATE DEFAULT NULL,
             fixture_date_window_end DATE DEFAULT NULL,
             fixture_date_window_checked_at DATETIME DEFAULT NULL,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $pdo->exec('INSERT IGNORE INTO livescore_api_settings (id) VALUES (1)');

        // Lazy migration for installs where the table already existed
        // before this window-tracking feature was added.
        cms_ensure_column($pdo, 'livescore_api_settings', 'fixture_date_window_start', 'DATE DEFAULT NULL');
        cms_ensure_column($pdo, 'livescore_api_settings', 'fixture_date_window_end', 'DATE DEFAULT NULL');
        cms_ensure_column($pdo, 'livescore_api_settings', 'fixture_date_window_checked_at', 'DATETIME DEFAULT NULL');
    }
}
