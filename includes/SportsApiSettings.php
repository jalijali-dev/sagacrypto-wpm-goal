<?php
declare(strict_types=1);

/**
 * Sports API Settings — one row per sport, unifying what used to be 4
 * separate tables (livescore_api_settings, basketball_api_settings,
 * f1_api_settings, sports_modules) into a single `sports_api_settings`
 * table (24 Jul 2026 consolidation). Adding a future sport (e.g. tennis)
 * means one INSERT here instead of a whole new settings table + class.
 *
 * LivescoreSettings/BasketballSettings/FormulaOneSettings (includes/*.php)
 * each query this table filtered by their own sport_key ('football',
 * 'basketball', 'motorsport' — same keys as the `sports` registry table)
 * and still expose their original per-sport public API, so callers
 * (football.php/basket.php/f1.php, cron scripts, admin actions) needed no
 * changes beyond the direct-SQL admin save/test/toggle handlers, which now
 * target this table with a sport_key filter instead of `WHERE id = 1`.
 *
 * This file only owns the two cross-sport concerns: nav/footer placement
 * (wpm_sports_modules_by_placement(), still named after the retired
 * sports_modules table to avoid touching site-bootstrap.php/site-footer.php's
 * call sites) and the cron kill-switch (wpm_sport_module_active()).
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';

function wpm_ensure_sports_api_settings_table(PDO $pdo): void
{
    $created = cms_ensure_table(
        $pdo,
        'sports_api_settings',
        "sport_id INT AUTO_INCREMENT PRIMARY KEY,
         sport_key VARCHAR(50) NOT NULL UNIQUE,
         label VARCHAR(100) NOT NULL,
         route_slug VARCHAR(100) NOT NULL,
         nav_placement ENUM('menu','footer','hidden') NOT NULL DEFAULT 'menu',
         sort_order INT NOT NULL DEFAULT 0,
         provider VARCHAR(100) NOT NULL,
         base_url VARCHAR(255) NOT NULL,
         api_key_enc VARCHAR(255) NULL,
         api_key_header VARCHAR(100) NOT NULL DEFAULT 'x-apisports-key',
         tracked_ids VARCHAR(255) NULL,
         sync_primary_interval INT UNSIGNED NOT NULL DEFAULT 300,
         sync_secondary_interval INT UNSIGNED NULL,
         cache_duration_live INT UNSIGNED NOT NULL DEFAULT 60,
         cache_duration_secondary INT UNSIGNED NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 0,
         auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
         last_test_status VARCHAR(20) NULL,
         last_test_message VARCHAR(255) NULL,
         last_test_at TIMESTAMP NULL,
         date_window_start DATE NULL,
         date_window_end DATE NULL,
         date_window_checked_at DATETIME NULL,
         page_title VARCHAR(150) NULL,
         page_subtitle VARCHAR(255) NULL,
         last_primary_sync_at DATETIME NULL,
         last_secondary_sync_at DATETIME NULL,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );

    if ($created) {
        $seed = $pdo->prepare(
            'INSERT INTO sports_api_settings (sport_key, label, route_slug, nav_placement, sort_order, provider, base_url, page_title, page_subtitle)
             VALUES (:sport_key, :label, :route_slug, :nav_placement, :sort_order, :provider, :base_url, :page_title, :page_subtitle)'
        );
        $seed->execute(['sport_key' => 'football', 'label' => 'Sepak Bola', 'route_slug' => 'football', 'nav_placement' => 'menu', 'sort_order' => 1, 'provider' => 'API-Football', 'base_url' => 'https://v3.football.api-sports.io', 'page_title' => 'Jadwal & Skor Pertandingan', 'page_subtitle' => 'Live score, jadwal hari ini dan besok, dikelompokkan per liga.']);
        $seed->execute(['sport_key' => 'basketball', 'label' => 'Basket', 'route_slug' => 'basket', 'nav_placement' => 'menu', 'sort_order' => 2, 'provider' => 'API-Basketball (NBA)', 'base_url' => 'https://v2.nba.api-sports.io', 'page_title' => 'Jadwal & Skor NBA', 'page_subtitle' => 'Live score dan jadwal pertandingan NBA, hari ini dan besok.']);
        $seed->execute(['sport_key' => 'motorsport', 'label' => 'Formula 1', 'route_slug' => 'f1', 'nav_placement' => 'footer', 'sort_order' => 3, 'provider' => 'API-Formula-1', 'base_url' => 'https://v1.formula-1.api-sports.io', 'page_title' => 'Kalender & Klasemen F1', 'page_subtitle' => 'Jadwal race musim ini, hasil podium, dan klasemen pembalap & konstruktor.']);
    }

    // Existing installs (table already created before 24 Jul 2026 v2) —
    // add the 2 new columns without touching anything else, then backfill
    // real current-page text so the site doesn't go blank for rows that
    // predate this field.
    $addedTitle = cms_ensure_column($pdo, 'sports_api_settings', 'page_title', 'VARCHAR(150) NULL AFTER date_window_checked_at');
    $addedSubtitle = cms_ensure_column($pdo, 'sports_api_settings', 'page_subtitle', 'VARCHAR(255) NULL AFTER page_title');
    if ($addedTitle || $addedSubtitle) {
        $backfill = $pdo->prepare('UPDATE sports_api_settings SET page_title = :title, page_subtitle = :subtitle WHERE sport_key = :key AND page_title IS NULL');
        $backfill->execute(['title' => 'Jadwal & Skor Pertandingan', 'subtitle' => 'Live score, jadwal hari ini dan besok, dikelompokkan per liga.', 'key' => 'football']);
        $backfill->execute(['title' => 'Jadwal & Skor NBA', 'subtitle' => 'Live score dan jadwal pertandingan NBA, hari ini dan besok.', 'key' => 'basketball']);
        $backfill->execute(['title' => 'Kalender & Klasemen F1', 'subtitle' => 'Jadwal race musim ini, hasil podium, dan klasemen pembalap & konstruktor.', 'key' => 'motorsport']);
    }

    // Quota self-throttling (24 Jul 2026) — sync_primary_interval/
    // sync_secondary_interval existed in the schema since the very first
    // sports_api_settings migration but were never actually read by any
    // cron script, only saved/displayed in the admin form. These 2
    // timestamps are what makes them real: LivescoreSync.php checks
    // "now - last_*_sync_at < sync_*_interval" before spending API calls,
    // instead of firing on every single cron invocation regardless of
    // how often the external crontab calls the script.
    cms_ensure_column($pdo, 'sports_api_settings', 'last_primary_sync_at', 'DATETIME NULL AFTER page_subtitle');
    cms_ensure_column($pdo, 'sports_api_settings', 'last_secondary_sync_at', 'DATETIME NULL AFTER last_primary_sync_at');
}

/**
 * Sports for a given nav_placement ('menu' or 'footer'), in sort order —
 * used by wpm_nav_menu() and the footer template. Also filters on
 * is_active=1 — a sport an admin has switched off must disappear from
 * navigation too, not just stop syncing (bug found 24 Jul 2026: this used
 * to filter on nav_placement alone, so turning "Aktif" off stopped cron
 * sync but left the nav item showing).
 */
function wpm_sports_modules_by_placement(PDO $pdo, string $placement): array
{
    wpm_ensure_sports_api_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM sports_api_settings WHERE is_active = 1 AND nav_placement = :p ORDER BY sort_order ASC, label ASC');
    $stmt->execute(['p' => $placement]);
    return $stmt->fetchAll();
}

/**
 * Cron gate — call this FIRST, before any per-sport settings/API work.
 * Fails open (true) if the row doesn't exist yet, so a brand-new sport
 * isn't silently blocked before an admin has ever configured it here.
 */
function wpm_sport_module_active(PDO $pdo, string $sportKey): bool
{
    wpm_ensure_sports_api_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT is_active FROM sports_api_settings WHERE sport_key = :key LIMIT 1');
    $stmt->execute(['key' => $sportKey]);
    $value = $stmt->fetchColumn();
    return $value === false ? true : (int) $value === 1;
}
