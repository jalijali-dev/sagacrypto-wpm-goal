<?php
declare(strict_types=1);

/**
 * Scoring pass for `game_predictions` — finds predictions still
 * `points_awarded IS NULL` whose fixture has reached a final status,
 * scores them, and accumulates the points into `game_players.total_points`.
 *
 * Called from the END of cron/sync_fixtures.php (see that file) — this
 * feature deliberately has NO cron of its own. It rides the EXISTING
 * fixtures-sync schedule instead of adding a new moving part, per
 * docs/DECISIONS.md (8 Sep 2026 entry): the sync job already runs
 * periodically and already knows the moment a fixture's status changes
 * to final, so hooking the scoring pass in right after it is simpler and
 * more reliable than a second independent cron racing against the same
 * data.
 */

require_once __DIR__ . '/GamesShared.php';

/**
 * Prediction scoring rule (rebalanced 9 Sep 2026, operator request —
 * was 5/2 at launch, see docs/DECISIONS.md for the old brief's original
 * "Sistem poin" if that context matters later):
 *   - Exact score match (both numbers identical)     -> 500 points
 *   - Correct result (win/lose/draw) but wrong score -> 200 points
 *   - Wrong result                                   -> 0 points
 *
 * `points_awarded` widened from TINYINT to SMALLINT UNSIGNED in
 * includes/GamesShared.php's wpm_games_ensure_schema() to fit 500 (a
 * plain TINYINT UNSIGNED tops out at 255) — if this rule is rebalanced
 * again later, check that column's ceiling (65,535) still fits before
 * bumping these numbers further.
 */
function wpm_calc_prediction_points(int $predictedHome, int $predictedAway, int $actualHome, int $actualAway): int
{
    if ($predictedHome === $actualHome && $predictedAway === $actualAway) {
        return 500;
    }
    $predictedResult = $predictedHome <=> $predictedAway; // -1 away win, 0 draw, 1 home win
    $actualResult = $actualHome <=> $actualAway;
    return $predictedResult === $actualResult ? 200 : 0;
}

/**
 * Scores every pending prediction whose fixture is now final. Safe to
 * call repeatedly (only ever touches rows where points_awarded IS NULL,
 * so an already-scored prediction is never re-scored/double-counted).
 *
 * @return array{scored: int}
 */
function wpm_score_pending_predictions(PDO $pdo): array
{
    wpm_games_ensure_schema($pdo);

    // 'FT'/'AET'/'PEN' — same final-status set as wpm_fixture_status_badge()
    // in includes/site-bootstrap.php (extra-time/penalties still count as
    // "match finished, final score is final").
    $stmt = $pdo->query(
        "SELECT gp.id, gp.player_id, gp.predicted_home, gp.predicted_away,
                f.home_score, f.away_score
         FROM game_predictions gp
         JOIN fixtures f ON f.id = gp.fixture_id
         WHERE gp.points_awarded IS NULL
           AND f.status_short IN ('FT', 'AET', 'PEN')
           AND f.home_score IS NOT NULL
           AND f.away_score IS NOT NULL"
    );
    $pending = $stmt->fetchAll();

    $updatePrediction = $pdo->prepare(
        'UPDATE game_predictions SET points_awarded = :points, scored_at = NOW() WHERE id = :id'
    );
    $addPlayerPoints = $pdo->prepare(
        'UPDATE game_players SET total_points = total_points + :points WHERE id = :player_id'
    );

    $scored = 0;
    foreach ($pending as $row) {
        $points = wpm_calc_prediction_points(
            (int) $row['predicted_home'],
            (int) $row['predicted_away'],
            (int) $row['home_score'],
            (int) $row['away_score']
        );

        $updatePrediction->execute(['points' => $points, 'id' => $row['id']]);
        if ($points > 0) {
            $addPlayerPoints->execute(['points' => $points, 'player_id' => $row['player_id']]);
        }
        $scored++;
    }

    return ['scored' => $scored];
}
