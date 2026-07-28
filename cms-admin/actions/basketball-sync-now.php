<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__, 2) . '/includes/BasketballSync.php';

/**
 * "Sync Sekarang" action for the NBA accordion section in
 * cms-admin/pages/livescore-api-settings.php (consolidated hub) —
 * runs the same sync the cron job runs (wpm_sync_nba_games()) by calling
 * the exact same function. Mirrors cms-admin/actions/livescore-sync-now.php
 * (football), just a single stage since NBA has no separate leagues/teams
 * pass — teams come embedded in the games response.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$settings = BasketballSettings::load($pdo);
if (!$settings['is_active']) {
    echo json_encode([
        'ok' => false,
        'error' => 'Fitur NBA sedang nonaktif — aktifkan "Fitur NBA aktif" dan simpan dulu sebelum sync manual.',
        'stages' => [],
    ]);
    exit;
}

// force=true — same reasoning as the football/F1 manual sync actions.
$gamesResult = wpm_sync_nba_games($pdo, true);
$stages = [
    [
        'name' => 'games',
        'label' => 'Sync games',
        'ok' => $gamesResult['ok'],
        'skipped_reason' => $gamesResult['skipped_reason'],
        'summary' => $gamesResult['skipped_reason'] !== null
            ? 'dilewati — ' . $gamesResult['skipped_reason']
            : "selesai ({$gamesResult['games_synced']} game, {$gamesResult['teams_upserted']} tim ter-upsert)",
        'messages' => $gamesResult['messages'],
    ],
];

echo json_encode([
    'ok' => true,
    'stages' => $stages,
    'finished_at' => date('d M Y, H:i:s'),
]);
