<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__, 2) . '/includes/LivescoreSync.php';

/**
 * "Sync Sekarang" action for cms-admin/pages/livescore-api-settings.php —
 * runs the same 2 sync stages the cron jobs run (leagues+teams, fixtures),
 * in the same order, by calling the exact same functions in
 * includes/LivescoreSync.php. Lets an admin trigger a manual sync from
 * the browser for testing without terminal/docker access. Safe to call
 * repeatedly — every stage is an idempotent upsert, same as cron.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$settings = LivescoreSettings::load($pdo);
if (!$settings['is_active']) {
    echo json_encode([
        'ok' => false,
        'error' => 'Fitur Livescore sedang nonaktif — aktifkan "Fitur Livescore aktif" dan simpan dulu sebelum sync manual.',
        'stages' => [],
    ]);
    exit;
}

$stages = [];

// force=true — an admin clicking "Sync Sekarang" wants a fresh pull now,
// not to be told to wait for the throttle interval (see
// LivescoreSettings::secondarySyncDue()).
$leaguesResult = wpm_sync_leagues_teams($pdo, true);
$stages[] = [
    'name' => 'leagues_teams',
    'label' => 'Sync leagues & teams',
    'ok' => $leaguesResult['ok'],
    'skipped_reason' => $leaguesResult['skipped_reason'],
    'summary' => $leaguesResult['skipped_reason'] !== null
        ? 'dilewati — ' . $leaguesResult['skipped_reason']
        : "selesai ({$leaguesResult['leagues_synced']} liga, {$leaguesResult['teams_synced']} tim)",
    'messages' => $leaguesResult['messages'],
];

// force=true — same reasoning as wpm_sync_leagues_teams() above.
$fixturesResult = wpm_sync_fixtures($pdo, true);
$stages[] = [
    'name' => 'fixtures',
    'label' => 'Sync fixtures',
    'ok' => $fixturesResult['ok'],
    'skipped_reason' => $fixturesResult['skipped_reason'],
    'summary' => $fixturesResult['skipped_reason'] !== null
        ? 'dilewati — ' . $fixturesResult['skipped_reason']
        : "selesai ({$fixturesResult['fixtures_synced']} pertandingan, {$fixturesResult['live_updated']} live diperbarui, {$fixturesResult['teams_upserted']} tim ter-upsert)",
    'messages' => $fixturesResult['messages'],
];

echo json_encode([
    'ok' => true,
    'stages' => $stages,
    'finished_at' => date('d M Y, H:i:s'),
]);
