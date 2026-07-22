<?php
declare(strict_types=1);

/**
 * Internal JSON endpoint powering the Livescore page's auto-refresh
 * (assets/js/livescore.js polls this every ~45s while on the "Live" tab).
 * Reads current live-match state straight from the local `fixtures` table
 * — this never calls API-Football directly; that call only ever happens
 * server-side in the (not-yet-built) cron sync job that keeps `fixtures`
 * up to date.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query(
        "SELECT id, status_short, status_long, elapsed, home_score, away_score
         FROM fixtures
         WHERE status_short IN ('1H','HT','2H','ET','P')"
    );
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

$fixtures = [];
foreach ($rows as $row) {
    $fixtures[] = [
        'id' => (int) $row['id'],
        'status_short' => (string) $row['status_short'],
        'elapsed' => $row['elapsed'] !== null ? (int) $row['elapsed'] : null,
        'home_score' => $row['home_score'] !== null ? (int) $row['home_score'] : null,
        'away_score' => $row['away_score'] !== null ? (int) $row['away_score'] : null,
    ];
}

echo json_encode(['ok' => true, 'fixtures' => $fixtures]);
