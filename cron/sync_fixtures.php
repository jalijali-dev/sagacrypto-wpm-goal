#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: sync `fixtures` from API-Football for every tracked league, plus
 * a live-fixtures refresh pass and a throttled free-plan date-window
 * probe. Run after sync_leagues_teams.php (FK on leagues/teams).
 *
 * Thin CLI wrapper — the actual logic lives in includes/LivescoreSync.php
 * (wpm_sync_fixtures()), shared with cms-admin/actions/
 * livescore-sync-now.php (the admin "Sync Sekarang" button) so both
 * callers run the exact same code.
 *
 *   php cron/sync_fixtures.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../includes/LivescoreSync.php';
require_once __DIR__ . '/../includes/SportsApiSettings.php';

// Sports Modules kill-switch (Fase 2, 24 Jul 2026) — checked first, cheaper
// than the per-sport settings gate below, so an admin's "stop everything"
// toggle on the Sports Settings page always wins immediately.
if (!wpm_sport_module_active($pdo, 'football')) {
    echo "[sync_fixtures] Skipped — sports_api_settings.is_active off for 'football'.\n";
    exit(0);
}

if (!LivescoreSettings::autoSyncAllowed($pdo)) {
    echo "[sync_fixtures] Skipped — is_active atau auto_sync_enabled sedang off.\n";
    exit(0);
}

$result = wpm_sync_fixtures($pdo);

if ($result['skipped_reason'] !== null) {
    echo "[sync_fixtures] Skipped — {$result['skipped_reason']}\n";
    exit(0);
}

foreach ($result['messages'] as $line) {
    echo "[sync_fixtures] {$line}\n";
}
echo "[sync_fixtures] Done. {$result['fixtures_synced']} fixtures synced, {$result['live_updated']} live updated.\n";
