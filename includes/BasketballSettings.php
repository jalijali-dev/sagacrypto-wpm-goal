<?php
declare(strict_types=1);

/**
 * Single-row config loader for the NBA/API-Basketball integration
 * (table: basketball_api_settings, id=1) — same "singleton settings row"
 * pattern as LivescoreSettings.php (football). Deliberately simpler:
 * no tracked_league_ids checklist, since NBA v2 is a single competition
 * (no leagues to pick from) — everything else (encrypted key, sync
 * toggles, learned date-window) mirrors the football settings shape.
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';
require_once __DIR__ . '/SportsRegistry.php'; // for syncSportVisibility()

final class BasketballSettings
{
    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, sync_games_interval: int, cache_duration_live: int,
     *   is_active: bool, auto_sync_enabled: bool,
     *   last_test_status: ?string, last_test_message: ?string, last_test_at: ?string
     * }
     */
    public static function load(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $row = $pdo->query('SELECT * FROM basketball_api_settings WHERE id = 1 LIMIT 1')->fetch();
        if ($row === false) {
            $pdo->exec('INSERT IGNORE INTO basketball_api_settings (id) VALUES (1)');
            $row = $pdo->query('SELECT * FROM basketball_api_settings WHERE id = 1 LIMIT 1')->fetch();
        }

        $apiKeyEnc = (string) ($row['api_key_enc'] ?? '');

        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'base_url' => rtrim((string) $row['base_url'], '/'),
            'api_key' => $apiKeyEnc !== '' ? cms_ai_decrypt($apiKeyEnc) : '',
            'api_key_header' => (string) $row['api_key_header'],
            'sync_games_interval' => (int) $row['sync_games_interval'],
            'cache_duration_live' => (int) $row['cache_duration_live'],
            'is_active' => (int) $row['is_active'] === 1,
            'auto_sync_enabled' => (int) $row['auto_sync_enabled'] === 1,
            'last_test_status' => $row['last_test_status'] !== null ? (string) $row['last_test_status'] : null,
            'last_test_message' => $row['last_test_message'] !== null ? (string) $row['last_test_message'] : null,
            'last_test_at' => $row['last_test_at'] !== null ? (string) $row['last_test_at'] : null,
        ];
    }

    /** True only when the feature is on AND the cron script is allowed to run. Gates cron only — manual "Sync Sekarang" uses isActive() so an admin can test before flipping auto-sync on. */
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
     * Same free-plan date-window learning as LivescoreSettings::getApiDateWindow()
     * (football) — same account family, same quirk, so nba.php can classify
     * an empty date the same way livescore.php does: "genuinely no games"
     * vs "provider can't fetch this date yet".
     *
     * @return array{start: ?string, end: ?string, checked_at: ?string}
     */
    public static function getGameDateWindow(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $row = $pdo->query(
            'SELECT game_date_window_start, game_date_window_end, game_date_window_checked_at
             FROM basketball_api_settings WHERE id = 1 LIMIT 1'
        )->fetch();

        return [
            'start' => $row['game_date_window_start'] !== null ? (string) $row['game_date_window_start'] : null,
            'end' => $row['game_date_window_end'] !== null ? (string) $row['game_date_window_end'] : null,
            'checked_at' => $row['game_date_window_checked_at'] !== null ? (string) $row['game_date_window_checked_at'] : null,
        ];
    }

    /** Persists a freshly-learned (or newly-cleared, when null/null) date window. Always stamps checked_at = now. */
    public static function saveGameDateWindow(PDO $pdo, ?string $start, ?string $end): void
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'UPDATE basketball_api_settings
             SET game_date_window_start = :start, game_date_window_end = :end, game_date_window_checked_at = NOW()
             WHERE id = 1'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);
    }

    /**
     * Keeps the /olahraga selector's "basketball" row in sync with this
     * feature's on/off switch — one checkbox (here) controls both whether
     * sync runs AND whether the selector shows an active "NBA" card
     * instead of "Segera Hadir", so there's no separate admin toggle to
     * forget about.
     */
    public static function syncSportVisibility(PDO $pdo, bool $isActive): void
    {
        wpm_ensure_sports_table($pdo);
        $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'basketball\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
    }

    private static function ensureSchema(PDO $pdo): void
    {
        cms_ensure_table(
            $pdo,
            'basketball_api_settings',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             provider VARCHAR(100) NOT NULL DEFAULT \'API-Basketball (NBA)\',
             base_url VARCHAR(255) NOT NULL DEFAULT \'https://v2.nba.api-sports.io\',
             api_key_enc VARCHAR(255) DEFAULT NULL,
             api_key_header VARCHAR(100) NOT NULL DEFAULT \'x-apisports-key\',
             sync_games_interval INT UNSIGNED NOT NULL DEFAULT 300,
             cache_duration_live INT UNSIGNED NOT NULL DEFAULT 60,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
             last_test_status VARCHAR(20) DEFAULT NULL,
             last_test_message VARCHAR(255) DEFAULT NULL,
             last_test_at TIMESTAMP NULL DEFAULT NULL,
             game_date_window_start DATE DEFAULT NULL,
             game_date_window_end DATE DEFAULT NULL,
             game_date_window_checked_at DATETIME DEFAULT NULL,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $pdo->exec('INSERT IGNORE INTO basketball_api_settings (id) VALUES (1)');
    }
}
