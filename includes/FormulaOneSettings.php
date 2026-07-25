<?php
declare(strict_types=1);

/**
 * Config loader for the Formula 1/API-Formula-1 integration — backed by
 * the unified `sports_api_settings` table (sport_key = 'motorsport'),
 * same pattern as BasketballSettings.php. No tracked-competition
 * checklist: F1 has one calendar per season, no leagues to pick from
 * (unlike football).
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';
require_once __DIR__ . '/SportsApiSettings.php';
require_once __DIR__ . '/SportsRegistry.php'; // for syncSportVisibility()

final class FormulaOneSettings
{
    private const SPORT_KEY = 'motorsport';

    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, sync_interval: int, sync_secondary_interval: ?int,
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
            'sync_interval' => (int) $row['sync_primary_interval'],
            'sync_secondary_interval' => $row['sync_secondary_interval'] !== null ? (int) $row['sync_secondary_interval'] : null,
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

    /** Keeps the `sports` table's "motorsport" row in sync with this feature's on/off switch — same pattern as BasketballSettings::syncSportVisibility(). */
    public static function syncSportVisibility(PDO $pdo, bool $isActive): void
    {
        wpm_ensure_sports_table($pdo);
        $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'motorsport\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
    }

    /**
     * Throttle gate for wpm_sync_f1_races() (quota-exhaustion fix, 24 Jul
     * 2026) — reads sync_interval (sync_primary_interval) from the DB. F1
     * has no live in-race data tracked by this app at all (see f1.php —
     * calendar + podium + standings only), so the whole race-calendar
     * sync is "non-live" and gated by this, unlike football/NBA where one
     * piece stays unthrottled.
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
        return (time() - strtotime($settings['last_primary_sync_at'])) >= $settings['sync_interval'];
    }

    /** Stamps last_primary_sync_at = now — call after a successful throttled race-calendar sync (see primarySyncDue()). */
    public static function markPrimarySynced(PDO $pdo): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare('UPDATE sports_api_settings SET last_primary_sync_at = NOW() WHERE sport_key = :key')
            ->execute(['key' => self::SPORT_KEY]);
    }

    /**
     * Throttle gate for wpm_sync_f1_standings() — separate cron/schedule
     * from the race calendar (same reasoning as football's leagues/teams
     * vs fixtures split), reads sync_secondary_interval, falls back to
     * sync_interval if never configured.
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
        $intervalSeconds = $settings['sync_secondary_interval'] ?? $settings['sync_interval'];
        return (time() - strtotime($settings['last_secondary_sync_at'])) >= $intervalSeconds;
    }

    /** Stamps last_secondary_sync_at = now — call after a successful throttled standings sync (see secondarySyncDue()). */
    public static function markSecondarySynced(PDO $pdo): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare('UPDATE sports_api_settings SET last_secondary_sync_at = NOW() WHERE sport_key = :key')
            ->execute(['key' => self::SPORT_KEY]);
    }

    /** Records a cron sync failure into last_test_* — see LivescoreSettings::recordSyncFailure() for the full rationale. */
    public static function recordSyncFailure(PDO $pdo, string $message): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare(
            "UPDATE sports_api_settings SET last_test_status = 'failed', last_test_message = :message, last_test_at = NOW() WHERE sport_key = :key"
        )->execute(['message' => $message, 'key' => self::SPORT_KEY]);
    }

    /** Counterpart to recordSyncFailure() — see LivescoreSettings::recordSyncSuccess() for the full rationale (25 Jul 2026 fix). */
    public static function recordSyncSuccess(PDO $pdo, string $message): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare(
            "UPDATE sports_api_settings SET last_test_status = 'success', last_test_message = :message, last_test_at = NOW() WHERE sport_key = :key"
        )->execute(['message' => $message, 'key' => self::SPORT_KEY]);
    }
}
