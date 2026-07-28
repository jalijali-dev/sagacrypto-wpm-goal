#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: sync `leagues` + `teams` from API-Football for every league id in
 * livescore_api_settings.tracked_league_ids.
 *
 * Run first, before sync_fixtures.php — that has a foreign key on
 * leagues/teams existing already.
 *
 * Thin CLI wrapper — the actual logic lives in includes/LivescoreSync.php
 * (wpm_sync_leagues_teams()), shared with cms-admin/actions/
 * livescore-sync-now.php (the admin "Sync Sekarang" button) so both
 * callers run the exact same code.
 *
 *   php cron/sync_leagues_teams.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../includes/LivescoreSync.php';
require_once __DIR__ . '/../includes/SportsApiSettings.php';

// Sports Modules kill-switch (Fase 2, 24 Jul 2026; sports_api_settings consolidation, 24 Jul 2026) — see sync_fixtures.php.
if (!wpm_sport_module_active($pdo, 'football')) {
    echo "[sync_leagues_teams] Skipped — sports_api_settings.is_active off for 'football'.\n";
    exit(0);
}

if (!LivescoreSettings::autoSyncAllowed($pdo)) {
    echo "[sync_leagues_teams] Skipped — is_active atau auto_sync_enabled sedang off.\n";
    exit(0);
}

$result = wpm_sync_leagues_teams($pdo);

if ($result['skipped_reason'] !== null) {
    echo "[sync_leagues_teams] Skipped — {$result['skipped_reason']}\n";
    exit(0);
}

foreach ($result['messages'] as $line) {
    echo "[sync_leagues_teams] {$line}\n";
}
echo "[sync_leagues_teams] Done. {$result['leagues_synced']} liga, {$result['teams_synced']} tim.\n";
