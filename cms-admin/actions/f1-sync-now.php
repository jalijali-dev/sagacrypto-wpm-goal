<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__, 2) . '/includes/FormulaOneSync.php';

/**
 * "Sync Sekarang" action for the Formula 1 accordion section in
 * cms-admin/pages/livescore-api-settings.php (consolidated hub) — runs
 * both F1 sync stages (races+podium, standings), in the same order the
 * cron jobs would, by calling the exact same functions in
 * includes/FormulaOneSync.php.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$settings = FormulaOneSettings::load($pdo);
if (!$settings['is_active']) {
    echo json_encode([
        'ok' => false,
        'error' => 'Fitur Formula 1 sedang nonaktif — aktifkan "Fitur Formula 1 aktif" dan simpan dulu sebelum sync manual.',
        'stages' => [],
    ]);
    exit;
}

$stages = [];

// force=true — an admin clicking "Sync Sekarang" wants a fresh pull now,
// not the quota-throttle skip message (see FormulaOneSettings::primarySyncDue()).
$racesResult = wpm_sync_f1_races($pdo, true);
$stages[] = [
    'name' => 'races',
    'label' => 'Sync kalender race + podium',
    'ok' => $racesResult['ok'],
    'skipped_reason' => $racesResult['skipped_reason'],
    'summary' => $racesResult['skipped_reason'] !== null
        ? 'dilewati — ' . $racesResult['skipped_reason']
        : "selesai ({$racesResult['races_synced']} sesi, podium {$racesResult['podium_backfilled']} race baru)",
    'messages' => $racesResult['messages'],
];

$standingsResult = wpm_sync_f1_standings($pdo, true);
$stages[] = [
    'name' => 'standings',
    'label' => 'Sync klasemen pembalap & konstruktor',
    'ok' => $standingsResult['ok'],
    'skipped_reason' => $standingsResult['skipped_reason'],
    'summary' => $standingsResult['skipped_reason'] !== null
        ? 'dilewati — ' . $standingsResult['skipped_reason']
        : "selesai ({$standingsResult['drivers_synced']} pembalap, {$standingsResult['constructors_synced']} konstruktor)",
    'messages' => $standingsResult['messages'],
];

echo json_encode([
    'ok' => true,
    'stages' => $stages,
    'finished_at' => date('d M Y, H:i:s'),
]);
