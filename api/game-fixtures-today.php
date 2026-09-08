<?php
declare(strict_types=1);

/**
 * Public endpoint: today's fixtures (WIB) + this browser's own
 * predictions, if any — feeds the "Prediksi Skor Harian" tab in
 * games/prediksi-trivia/index.php (assets/games/js/prediksi-trivia.js).
 *
 * Same WIB day-window query pattern as football.php (CONVERT_TZ — see
 * includes/TimeHelpers.php's docblock for why storage stays UTC and
 * only display/day-grouping converts). `is_locked` is computed HERE,
 * server-side, from the DB's own clock — the frontend uses it purely
 * for display, never as the actual submit gate (that check happens
 * again, independently, in game-predict.php — never trust a client
 * timestamp for that).
 *
 * Request:  GET, optional ?browser_id=
 * Response: JSON {success:true, fixtures:[...]}
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

$browserId = trim((string) ($_GET['browser_id'] ?? ''));
$hasBrowserId = $browserId !== '' && wpm_games_browser_id_is_valid($browserId);

$today = wpm_today_wib();

try {
    $stmt = $pdo->prepare(
        "SELECT f.id, f.kickoff_at, f.status_short, f.home_score, f.away_score,
                ht.name AS home_name, ht.logo AS home_logo,
                at.name AS away_name, at.logo AS away_logo,
                l.name AS league_name
         FROM fixtures f
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         JOIN leagues l ON l.id = f.league_id
         WHERE DATE(CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00')) = :today
         ORDER BY f.kickoff_at ASC"
    );
    $stmt->execute(['today' => $today]);
    $fixtures = $stmt->fetchAll();
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Could not load fixtures.'], 500);
}

$predictionsByFixture = [];
if ($hasBrowserId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT gp.fixture_id, gp.predicted_home, gp.predicted_away, gp.points_awarded
             FROM game_predictions gp
             JOIN game_players p ON p.id = gp.player_id
             WHERE p.browser_id = :browser_id'
        );
        $stmt->execute(['browser_id' => $browserId]);
        foreach ($stmt->fetchAll() as $row) {
            $predictionsByFixture[(int) $row['fixture_id']] = [
                'predicted_home' => (int) $row['predicted_home'],
                'predicted_away' => (int) $row['predicted_away'],
                'points_awarded' => $row['points_awarded'] !== null ? (int) $row['points_awarded'] : null,
            ];
        }
    } catch (Throwable $e) {
        $predictionsByFixture = [];
    }
}

$nowUtc = time();
$out = [];
foreach ($fixtures as $f) {
    $fixtureId = (int) $f['id'];
    $kickoffTs = strtotime((string) $f['kickoff_at'] . ' UTC');
    $out[] = [
        'id' => $fixtureId,
        'kickoff_at_wib' => wpm_format_match_time($f['kickoff_at'], 'H:i'),
        'league_name' => $f['league_name'],
        'home_name' => $f['home_name'],
        'home_logo' => $f['home_logo'],
        'away_name' => $f['away_name'],
        'away_logo' => $f['away_logo'],
        'status_short' => $f['status_short'],
        'home_score' => $f['home_score'] !== null ? (int) $f['home_score'] : null,
        'away_score' => $f['away_score'] !== null ? (int) $f['away_score'] : null,
        'is_locked' => $kickoffTs === false ? true : $kickoffTs <= $nowUtc,
        'my_prediction' => $predictionsByFixture[$fixtureId] ?? null,
    ];
}

wpm_games_respond(['success' => true, 'fixtures' => $out]);
