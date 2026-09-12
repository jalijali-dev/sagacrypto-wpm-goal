<?php
declare(strict_types=1);

/**
 * Public endpoint: one player's public profile — nickname, total_points,
 * and full match-by-match Prediksi Skor history (12 Sep 2026, operator
 * request: "mau nampilin history tebakan lengkap per-match", public page
 * anyone can view by URL). Feeds games/prediksi-trivia/user.php.
 *
 * Looked up by `public_code` (see includes/GamesShared.php's schema
 * comment for `game_players.public_code` — a short random code, NOT the
 * raw auto-increment `id`, so this page isn't trivially enumerable by
 * incrementing a number in the URL) — never by nickname, since nicknames
 * aren't unique (two players can pick the exact same one).
 *
 * FAIRNESS NOTE (deliberate scope decision, not explicitly asked in the
 * brief but necessary): only returns predictions for matches that are
 * ALREADY SCORED (`points_awarded IS NOT NULL`, i.e. the match finished
 * and the scoring cron already ran) — a prediction for a match that
 * hasn't kicked off yet is deliberately withheld here even though it's
 * "this player's own data", because this page is PUBLIC. Showing an
 * unscored prediction would let anyone who knows/guesses someone's public
 * URL see their pick before kickoff and copy it into their own — that
 * undermines the whole point of predicting blind. The private "Tebakan
 * Saya" box in prediksi-trivia.js (same browser only, via browser_id) is
 * unaffected by this — that one legitimately shows a player's own
 * in-progress picks to THEMSELVES.
 *
 * Request:  GET, required ?code=<public_code>
 * Response: 200 {success:true, data:{nickname, total_points, history:[...]}}
 *           400 {success:false, message} — missing/invalid code param
 *           404 {success:false, message} — no player with that code
 *           405 {success:false, message} — wrong HTTP method
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../includes/GamesShared.php';
require_once __DIR__ . '/../includes/TimeHelpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    wpm_games_respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    wpm_games_ensure_schema($pdo);
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Schema not ready.'], 500);
}

// Same loose shape check as browser_id elsewhere — the code is
// bin2hex(random_bytes(4)), always exactly 8 lowercase hex chars, but
// kept slightly lenient (1-16) rather than hardcoding "8" in case the
// generation length is ever tuned later.
$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '' || preg_match('/^[a-f0-9]{1,16}$/i', $code) !== 1) {
    wpm_games_respond(['success' => false, 'message' => 'Sertakan ?code= yang valid.'], 400);
}

try {
    $playerStmt = $pdo->prepare('SELECT id, nickname, total_points FROM game_players WHERE public_code = :code');
    $playerStmt->execute(['code' => $code]);
    $player = $playerStmt->fetch();

    if ($player === false) {
        wpm_games_respond(['success' => false, 'message' => 'Pemain tidak ditemukan.'], 404);
    }

    // See file docblock's FAIRNESS NOTE — only scored (match already
    // finished) predictions are exposed on this PUBLIC page.
    $historyStmt = $pdo->prepare(
        'SELECT gp.predicted_home, gp.predicted_away, gp.points_awarded,
                f.kickoff_at, f.status_short, f.home_score, f.away_score,
                l.name AS league_name, ht.name AS home_name, at.name AS away_name,
                ht.logo AS home_logo, at.logo AS away_logo
         FROM game_predictions gp
         JOIN fixtures f ON f.id = gp.fixture_id
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE gp.player_id = :player_id AND gp.points_awarded IS NOT NULL
         ORDER BY f.kickoff_at DESC
         LIMIT 100'
    );
    $historyStmt->execute(['player_id' => (int) $player['id']]);
    $historyRows = $historyStmt->fetchAll();
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Could not load player history.'], 500);
}

$history = array_map(static function (array $r): array {
    return [
        'league_name' => (string) $r['league_name'],
        'home_name' => (string) $r['home_name'],
        'away_name' => (string) $r['away_name'],
        'home_logo' => $r['home_logo'] !== null ? (string) $r['home_logo'] : null,
        'away_logo' => $r['away_logo'] !== null ? (string) $r['away_logo'] : null,
        'kickoff_at_wib' => wpm_format_match_time($r['kickoff_at'], 'd/m/Y H:i'),
        'home_score' => (int) $r['home_score'],
        'away_score' => (int) $r['away_score'],
        'predicted_home' => (int) $r['predicted_home'],
        'predicted_away' => (int) $r['predicted_away'],
        'points_awarded' => (int) $r['points_awarded'],
    ];
}, $historyRows);

wpm_games_respond([
    'success' => true,
    'data' => [
        'nickname' => (string) $player['nickname'],
        'total_points' => (int) $player['total_points'],
        'history' => $history,
    ],
]);
