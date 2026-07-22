#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: sync `f1_races` (current season calendar) + backfill
 * `f1_race_podium` for newly-completed races.
 *
 * Thin CLI wrapper — the actual logic lives in includes/FormulaOneSync.php
 * (wpm_sync_f1_races()), shared with cms-admin/actions/f1-sync-now.php.
 *
 *   php cron/sync_f1_races.php
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../includes/FormulaOneSync.php';

if (!FormulaOneSettings::autoSyncAllowed($pdo)) {
    echo "[sync_f1_races] Skipped — is_active atau auto_sync_enabled sedang off.\n";
    exit(0);
}

$result = wpm_sync_f1_races($pdo);

if ($result['skipped_reason'] !== null) {
    echo "[sync_f1_races] Skipped — {$result['skipped_reason']}\n";
    exit(0);
}

foreach ($result['messages'] as $line) {
    echo "[sync_f1_races] {$line}\n";
}
echo "[sync_f1_races] Done. {$result['races_synced']} sesi disync, podium {$result['podium_backfilled']} race baru diisi.\n";
