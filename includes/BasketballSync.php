<?php
declare(strict_types=1);

/**
 * Shared NBA/API-Basketball sync logic — the actual work behind
 * cron/sync_nba_games.php, called by both the CLI cron wrapper and
 * cms-admin/actions/basketball-sync-now.php (mirrors the football
 * LivescoreSync.php split). Never echoes, never exit()s — returns a
 * structured summary so callers format progress however they need.
 *
 * Learned from the football integration (API-Football's free plan blocks
 * /teams for the current season with no workaround): teams are upserted
 * straight from the `teams` object every /games response already embeds
 * (id/name/code/city/logo), never from a separate /teams call — so this
 * sync never depends on an endpoint that might turn out to be similarly
 * restricted. NBA v2 is a single competition (no leagues to track), so
 * there's no tracked-id filtering step like football's — every game a
 * date query returns is relevant.
 */

require_once __DIR__ . '/BasketballSettings.php';
require_once __DIR__ . '/ApiBasketballClient.php';

/** Self-migrating schema for the two NBA tables — new tables, no legacy migration to account for. */
function wpm_ensure_nba_tables(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'nba_teams',
        'id INT UNSIGNED NOT NULL PRIMARY KEY,
         name VARCHAR(150) NOT NULL,
         code VARCHAR(10) DEFAULT NULL,
         city VARCHAR(100) DEFAULT NULL,
         logo VARCHAR(255) DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    );

    cms_ensure_table(
        $pdo,
        'nba_games',
        'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
         season SMALLINT UNSIGNED NOT NULL DEFAULT 0,
         stage TINYINT UNSIGNED DEFAULT NULL,
         game_date DATETIME NOT NULL,
         status_short TINYINT UNSIGNED NOT NULL DEFAULT 1,
         status_long VARCHAR(30) DEFAULT NULL,
         period_current TINYINT UNSIGNED DEFAULT NULL,
         home_team_id INT UNSIGNED NOT NULL,
         away_team_id INT UNSIGNED NOT NULL,
         home_score SMALLINT UNSIGNED DEFAULT NULL,
         away_score SMALLINT UNSIGNED DEFAULT NULL,
         home_linescore VARCHAR(50) DEFAULT NULL,
         away_linescore VARCHAR(50) DEFAULT NULL,
         arena_name VARCHAR(150) DEFAULT NULL,
         arena_city VARCHAR(150) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_nba_games_date (game_date),
         CONSTRAINT fk_nba_games_home FOREIGN KEY (home_team_id) REFERENCES nba_teams (id),
         CONSTRAINT fk_nba_games_away FOREIGN KEY (away_team_id) REFERENCES nba_teams (id)'
    );
}

/**
 * Sync `nba_games` (today + tomorrow) + embedded `nba_teams` upsert, plus
 * a throttled (1x/24h) probe learning the free-plan's currently allowed
 * /games?date= window (same account family/quirk as API-Football).
 *
 * @return array{ok: bool, skipped_reason: ?string, games_synced: int, teams_upserted: int, messages: list<string>}
 */
function wpm_sync_nba_games(PDO $pdo): array
{
    $messages = [];
    wpm_ensure_nba_tables($pdo);

    if (!BasketballSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur NBA sedang nonaktif.', 'games_synced' => 0, 'teams_upserted' => 0, 'messages' => $messages];
    }

    $settings = BasketballSettings::load($pdo);
    $client = ApiBasketballClient::fromSettings($settings);

    $teamUpsert = $pdo->prepare(
        'INSERT INTO nba_teams (id, name, code, city, logo, is_active)
         VALUES (:id, :name, :code, :city, :logo, 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), city = VALUES(city), logo = VALUES(logo)'
    );

    $gameUpsert = $pdo->prepare(
        'INSERT INTO nba_games (
            id, season, stage, game_date, status_short, status_long, period_current,
            home_team_id, away_team_id, home_score, away_score, home_linescore, away_linescore,
            arena_name, arena_city
         ) VALUES (
            :id, :season, :stage, :game_date, :status_short, :status_long, :period_current,
            :home_team_id, :away_team_id, :home_score, :away_score, :home_linescore, :away_linescore,
            :arena_name, :arena_city
         )
         ON DUPLICATE KEY UPDATE
           stage = VALUES(stage), game_date = VALUES(game_date), status_short = VALUES(status_short),
           status_long = VALUES(status_long), period_current = VALUES(period_current),
           home_score = VALUES(home_score), away_score = VALUES(away_score),
           home_linescore = VALUES(home_linescore), away_linescore = VALUES(away_linescore),
           arena_name = VALUES(arena_name), arena_city = VALUES(arena_city)'
    );

    $seenTeamIds = [];
    $teamsUpserted = 0;
    $gamesSynced = 0;

    $upsertGames = static function (array $games) use ($gameUpsert, $teamUpsert, &$seenTeamIds, &$teamsUpserted, &$gamesSynced): void {
        foreach ($games as $game) {
            $teams = $game['teams'] ?? [];
            $home = $teams['home'] ?? [];
            $visitor = $teams['visitors'] ?? [];

            if (empty($game['id']) || empty($home['id']) || empty($visitor['id'])) {
                continue;
            }

            foreach ([$home, $visitor] as $team) {
                $teamId = (int) $team['id'];
                if (isset($seenTeamIds[$teamId])) {
                    continue;
                }
                $teamUpsert->execute([
                    'id' => $teamId,
                    'name' => (string) ($team['name'] ?? ''),
                    'code' => (string) ($team['code'] ?? ''),
                    'city' => (string) ($team['city'] ?? ''),
                    'logo' => (string) ($team['logo'] ?? ''),
                ]);
                $seenTeamIds[$teamId] = true;
                $teamsUpserted++;
            }

            $scores = $game['scores'] ?? [];
            $homeLinescore = $scores['home']['linescore'] ?? null;
            $awayLinescore = $scores['visitors']['linescore'] ?? null;

            $gameUpsert->execute([
                'id' => (int) $game['id'],
                'season' => (int) ($game['season'] ?? 0),
                'stage' => $game['stage'] ?? null,
                'game_date' => date('Y-m-d H:i:s', strtotime((string) ($game['date']['start'] ?? 'now'))),
                'status_short' => (int) ($game['status']['short'] ?? 1),
                'status_long' => (string) ($game['status']['long'] ?? ''),
                'period_current' => $game['periods']['current'] ?? null,
                'home_team_id' => (int) $home['id'],
                'away_team_id' => (int) $visitor['id'],
                'home_score' => $scores['home']['points'] ?? null,
                'away_score' => $scores['visitors']['points'] ?? null,
                'home_linescore' => is_array($homeLinescore) ? implode(',', $homeLinescore) : null,
                'away_linescore' => is_array($awayLinescore) ? implode(',', $awayLinescore) : null,
                'arena_name' => (string) ($game['arena']['name'] ?? ''),
                'arena_city' => (string) ($game['arena']['city'] ?? ''),
            ]);
            $gamesSynced++;
        }
    };

    foreach ([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))] as $date) {
        $result = $client->games(['date' => $date]);
        if (!$result['ok']) {
            $messages[] = "Date {$date}: FAILED ({$result['error']})";
            $parsedWindow = ApiBasketballClient::parseDateWindowError($result['error']);
            if ($parsedWindow !== null) {
                BasketballSettings::saveGameDateWindow($pdo, $parsedWindow['start'], $parsedWindow['end']);
                $messages[] = "Date window updated from failure: {$parsedWindow['start']} to {$parsedWindow['end']}";
            }
            continue;
        }
        $before = $gamesSynced;
        $upsertGames($result['data']['response'] ?? []);
        $messages[] = "Date {$date}: " . ($gamesSynced - $before) . ' games synced';
    }

    if ($teamsUpserted > 0) {
        $messages[] = "{$teamsUpserted} team(s) upserted from embedded game data.";
    }

    // Throttled (1x/24h) probe learning the free-plan date window, same pattern as football.
    $window = BasketballSettings::getGameDateWindow($pdo);
    $needsProbe = $window['checked_at'] === null || (time() - strtotime($window['checked_at'])) > 86400;

    if ($needsProbe) {
        $probeDate = date('Y-m-d', strtotime('+90 days'));
        $probeResult = $client->games(['date' => $probeDate]);
        $parsedWindow = ApiBasketballClient::parseDateWindowError($probeResult['error'] ?? '');

        if ($parsedWindow !== null) {
            BasketballSettings::saveGameDateWindow($pdo, $parsedWindow['start'], $parsedWindow['end']);
            $messages[] = "Date window probe: learned {$parsedWindow['start']} to {$parsedWindow['end']}";
        } elseif ($probeResult['ok']) {
            BasketballSettings::saveGameDateWindow($pdo, null, null);
            $messages[] = "Date window probe: {$probeDate} succeeded — no date restriction detected.";
        } else {
            $messages[] = "Date window probe: inconclusive ({$probeResult['error']}), will retry next run.";
        }
    }

    return ['ok' => true, 'skipped_reason' => null, 'games_synced' => $gamesSynced, 'teams_upserted' => $teamsUpserted, 'messages' => $messages];
}
