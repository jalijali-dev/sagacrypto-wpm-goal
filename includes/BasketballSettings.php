<?php
declare(strict_types=1);

/**
 * Config loader for the NBA/API-Basketball integration — backed by the
 * unified `sports_api_settings` table (sport_key = 'basketball'), same
 * pattern as LivescoreSettings.php (football). Deliberately simpler: no
 * tracked_league_ids checklist, since NBA v2 is a single competition (no
 * leagues to pick from) — everything else (encrypted key, sync toggles,
 * learned date-window) mirrors the football settings shape.
 */

require_once __DIR__ . '/../cms-admin/config/app.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/ai-helpers.php';
require_once __DIR__ . '/SportsApiSettings.php';
require_once __DIR__ . '/SportsRegistry.php'; // for syncSportVisibility()

final class BasketballSettings
{
    private const SPORT_KEY = 'basketball';

    /**
     * @return array{
     *   id: int, provider: string, base_url: string, api_key: string,
     *   api_key_header: string, sync_games_interval: int, cache_duration_live: int,
     *   is_active: bool, auto_sync_enabled: bool,
     *   nav_placement: string, sort_order: int,
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
            'sync_games_interval' => (int) $row['sync_primary_interval'],
            'cache_duration_live' => (int) $row['cache_duration_live'],
            'is_active' => (int) $row['is_active'] === 1,
            'auto_sync_enabled' => (int) $row['auto_sync_enabled'] === 1,
            'nav_placement' => (string) $row['nav_placement'],
            'page_title' => $row['page_title'] !== null ? (string) $row['page_title'] : null,
            'page_subtitle' => $row['page_subtitle'] !== null ? (string) $row['page_subtitle'] : null,
            'sort_order' => (int) $row['sort_order'],
            'last_primary_sync_at' => $row['last_primary_sync_at'] !== null ? (string) $row['last_primary_sync_at'] : null,
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
     * (football) — same account family, same quirk, so basket.php can classify
     * an empty date the same way football.php does: "genuinely no games"
     * vs "provider can't fetch this date yet".
     *
     * @return array{start: ?string, end: ?string, checked_at: ?string}
     */
    public static function getGameDateWindow(PDO $pdo): array
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
    public static function saveGameDateWindow(PDO $pdo, ?string $start, ?string $end): void
    {
        wpm_ensure_sports_api_settings_table($pdo);

        $stmt = $pdo->prepare(
            'UPDATE sports_api_settings
             SET date_window_start = :start, date_window_end = :end, date_window_checked_at = NOW()
             WHERE sport_key = :key'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'key' => self::SPORT_KEY]);
    }

    /**
     * Keeps the `sports` table's "basketball" row in sync with this
     * feature's on/off switch — one checkbox (here) controls both whether
     * sync runs AND whether the admin accordion/homepage filter chip show
     * this as active, so there's no separate admin toggle to forget about.
     */
    public static function syncSportVisibility(PDO $pdo, bool $isActive): void
    {
        wpm_ensure_sports_table($pdo);
        $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'basketball\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
    }

    /**
     * Throttle gate for NBA's "tomorrow" games fetch (quota-exhaustion
     * fix, 24 Jul 2026) — reads sync_games_interval (sync_primary_interval)
     * from the DB. Unlike football, API-Basketball has no dedicated
     * live-only endpoint (see ApiBasketballClient — just games?date=), so
     * "today" stays unthrottled (it's the one date that can contain live
     * games) while "tomorrow" — pure non-live schedule — is gated by this.
     */
    public static function primarySyncDue(PDO $pdo, bool $force = false): bool
    {
        if ($force) {
            return true;
        }
        $settings = self::load($pdo);
        $lastSyncAt = $settings['last_primary_sync_at'] ?? null;
        if ($lastSyncAt === null) {
            return true;
        }
        return (time() - strtotime($lastSyncAt)) >= $settings['sync_games_interval'];
    }

    /** Stamps last_primary_sync_at = now — call after a successful throttled "tomorrow" games pass (see primarySyncDue()). */
    public static function markPrimarySynced(PDO $pdo): void
    {
        wpm_ensure_sports_api_settings_table($pdo);
        $pdo->prepare('UPDATE sports_api_settings SET last_primary_sync_at = NOW() WHERE sport_key = :key')
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
