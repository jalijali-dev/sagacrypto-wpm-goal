#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: sync `nba_games` (+ embedded `nba_teams`) from API-Basketball/NBA
 * v2 for today + tomorrow, plus a throttled free-plan date-window probe.
 *
 * Thin CLI wrapper — the actual logic lives in includes/BasketballSync.php
 * (wpm_sync_nba_games()), shared with cms-admin/actions/
 * basketball-sync-now.php (the admin "Sync Sekarang" button) so both
 * callers run the exact same code. Mirrors cron/sync_fixtures.php
 * (football)'s structure.
 *
 *   php cron/sync_nba_games.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../includes/BasketballSync.php';
require_once __DIR__ . '/../includes/SportsApiSettings.php';

// Sports Modules kill-switch (Fase 2, 24 Jul 2026; sports_api_settings consolidation, 24 Jul 2026) — see sync_fixtures.php.
if (!wpm_sport_module_active($pdo, 'basketball')) {
    echo "[sync_nba_games] Skipped — sports_api_settings.is_active off for 'basketball'.\n";
    exit(0);
}

if (!BasketballSettings::autoSyncAllowed($pdo)) {
    echo "[sync_nba_games] Skipped — is_active atau auto_sync_enabled sedang off.\n";
    exit(0);
}

$result = wpm_sync_nba_games($pdo);

if ($result['skipped_reason'] !== null) {
    echo "[sync_nba_games] Skipped — {$result['skipped_reason']}\n";
    exit(0);
}

foreach ($result['messages'] as $line) {
    echo "[sync_nba_games] {$line}\n";
}
echo "[sync_nba_games] Done. {$result['games_synced']} games synced, {$result['teams_upserted']} teams upserted.\n";
