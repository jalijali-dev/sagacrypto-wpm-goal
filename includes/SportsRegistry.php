<?php
declare(strict_types=1);

/**
 * Sport registry (Sagagoal's pivot from a football-only site to a
 * multi-sport livescore site). Powers the sport accordion list in
 * cms-admin/pages/livescore-api-settings.php and the homepage's sport
 * filter chips (wpm_sport_filter_row()) — the /olahraga selector this
 * table originally fed was removed 24 Jul 2026 (see wpm_nav_menu()).
 * `is_active = 0` rows still matter for the admin accordion's "Segera
 * Hadir" state. Self-migrating (cms_ensure_table) and seeds the initial
 * 3 rows on first call.
 *
 * Pulled out of site-bootstrap.php into its own tiny file so backend-only
 * settings classes (BasketballSettings::syncSportVisibility(), and future
 * per-sport equivalents) can flip a sport's is_active flag without
 * pulling in the entire public-frontend bootstrap.
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';

function wpm_ensure_sports_table(PDO $pdo): void
{
    $created = cms_ensure_table(
        $pdo,
        'sports',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         `key` VARCHAR(50) NOT NULL,
         name VARCHAR(100) NOT NULL,
         icon VARCHAR(50) DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 0,
         sort_order INT NOT NULL DEFAULT 0,
         notes VARCHAR(255) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_sports_key (`key`)"
    );

    if ($created) {
        $seed = $pdo->prepare(
            'INSERT INTO sports (`key`, name, icon, is_active, sort_order, notes) VALUES (:key, :name, :icon, :is_active, :sort_order, :notes)'
        );
        $seed->execute(['key' => 'football', 'name' => 'Sepak Bola', 'icon' => 'football', 'is_active' => 1, 'sort_order' => 1, 'notes' => 'Provider: API-Football.']);
        $seed->execute(['key' => 'basketball', 'name' => 'Basket', 'icon' => 'basketball', 'is_active' => 0, 'sort_order' => 2, 'notes' => 'NBA — provider: API-Basketball (NBA v2). Aktifkan di Livescore API Settings (accordion Basket) setelah API key valid.']);
        $seed->execute(['key' => 'motorsport', 'name' => 'Formula 1', 'icon' => 'motorsport', 'is_active' => 0, 'sort_order' => 3, 'notes' => 'Provider: API-Formula-1 (bukan MotoGP). Aktifkan di Livescore API Settings (accordion Formula 1) setelah API key valid.']);
    }
}

/** All sports (active + "Segera Hadir" placeholders), in admin-configured order — used by the admin Livescore API Settings accordion. */
function wpm_all_sports(PDO $pdo): array
{
    wpm_ensure_sports_table($pdo);
    try {
        return $pdo->query('SELECT * FROM sports ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
