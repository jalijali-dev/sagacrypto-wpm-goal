<?php
declare(strict_types=1);

/**
 * Public endpoint: top 20 `game_players` by total_points — combined
 * leaderboard across both Prediksi Skor and Trivia Cepat (they share
 * the same total_points counter per player, see docs/DECISIONS.md,
 * 8 Sep 2026 entry). Read-only, no browser_id needed.
 *
 * Request:  GET
 * Response: JSON {success:true, leaderboard:[{nickname, total_points}]}
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../includes/GamesShared.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    wpm_games_respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    wpm_games_ensure_schema($pdo);
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Schema not ready.'], 500);
}

try {
    $stmt = $pdo->query(
        'SELECT nickname, total_points FROM game_players
         WHERE total_points > 0
         ORDER BY total_points DESC, updated_at ASC
         LIMIT 20'
    );
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Could not load leaderboard.'], 500);
}

$leaderboard = array_map(static function (array $r): array {
    return ['nickname' => (string) $r['nickname'], 'total_points' => (int) $r['total_points']];
}, $rows);

wpm_games_respond(['success' => true, 'leaderboard' => $leaderboard]);
