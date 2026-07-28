#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: sync `f1_driver_standings` + `f1_constructor_standings` for the
 * current season. Runs independently of sync_f1_races.php — standings
 * only meaningfully change after a race, so this can run on a slower
 * schedule.
 *
 * Thin CLI wrapper — the actual logic lives in includes/FormulaOneSync.php
 * (wpm_sync_f1_standings()), shared with cms-admin/actions/f1-sync-now.php.
 *
 *   php cron/sync_f1_standings.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../includes/FormulaOneSync.php';
require_once __DIR__ . '/../includes/SportsApiSettings.php';

// Sports Modules kill-switch (Fase 2, 24 Jul 2026) — see sync_fixtures.php.
if (!wpm_sport_module_active($pdo, 'motorsport')) {
    echo "[sync_f1_standings] Skipped — sports_api_settings.is_active off for 'motorsport'.\n";
    exit(0);
}

if (!FormulaOneSettings::autoSyncAllowed($pdo)) {
    echo "[sync_f1_standings] Skipped — is_active atau auto_sync_enabled sedang off.\n";
    exit(0);
}

$result = wpm_sync_f1_standings($pdo);

if ($result['skipped_reason'] !== null) {
    echo "[sync_f1_standings] Skipped — {$result['skipped_reason']}\n";
    exit(0);
}

foreach ($result['messages'] as $line) {
    echo "[sync_f1_standings] {$line}\n";
}
echo "[sync_f1_standings] Done. {$result['drivers_synced']} pembalap, {$result['constructors_synced']} konstruktor.\n";
