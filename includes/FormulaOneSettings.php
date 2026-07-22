<?php
declare(strict_types=1);

/**
 * Single-row config loader for the Formula 1/API-Formula-1 integration
 * (table: f1_api_settings, id=1) — same pattern as BasketballSettings.php.
 * No tracked-competition checklist: F1 has one calendar per season, no
 * leagues to pick from (unlike football).
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';
require_once __DIR__ . '/SportsRegistry.php'; // for syncSportVisibility()

final class FormulaOneSettings
{
    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, sync_interval: int,
     *   is_active: bool, auto_sync_enabled: bool,
     *   last_test_status: ?string, last_test_message: ?string, last_test_at: ?string
     * }
     */
    public static function load(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $row = $pdo->query('SELECT * FROM f1_api_settings WHERE id = 1 LIMIT 1')->fetch();
        if ($row === false) {
            $pdo->exec('INSERT IGNORE INTO f1_api_settings (id) VALUES (1)');
            $row = $pdo->query('SELECT * FROM f1_api_settings WHERE id = 1 LIMIT 1')->fetch();
        }

        $apiKeyEnc = (string) ($row['api_key_enc'] ?? '');

        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'base_url' => rtrim((string) $row['base_url'], '/'),
            'api_key' => $apiKeyEnc !== '' ? cms_ai_decrypt($apiKeyEnc) : '',
            'api_key_header' => (string) $row['api_key_header'],
            'sync_interval' => (int) $row['sync_interval'],
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

    /** Keeps /olahraga's "motorsport" row in sync with this feature's on/off switch — same pattern as BasketballSettings::syncSportVisibility(). */
    public static function syncSportVisibility(PDO $pdo, bool $isActive): void
    {
        wpm_ensure_sports_table($pdo);
        $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'motorsport\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
    }

    private static function ensureSchema(PDO $pdo): void
    {
        cms_ensure_table(
            $pdo,
            'f1_api_settings',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             provider VARCHAR(100) NOT NULL DEFAULT \'API-Formula-1\',
             base_url VARCHAR(255) NOT NULL DEFAULT \'https://v1.formula-1.api-sports.io\',
             api_key_enc VARCHAR(255) DEFAULT NULL,
             api_key_header VARCHAR(100) NOT NULL DEFAULT \'x-apisports-key\',
             sync_interval INT UNSIGNED NOT NULL DEFAULT 3600,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
             last_test_status VARCHAR(20) DEFAULT NULL,
             last_test_message VARCHAR(255) DEFAULT NULL,
             last_test_at TIMESTAMP NULL DEFAULT NULL,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $pdo->exec('INSERT IGNORE INTO f1_api_settings (id) VALUES (1)');
    }
}
