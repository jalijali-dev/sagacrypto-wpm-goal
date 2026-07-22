<?php
declare(strict_types=1);

/**
 * Shared Formula 1/API-Formula-1 sync logic — behind cron/sync_f1_races.php
 * and cron/sync_f1_standings.php, called by both the CLI wrappers and
 * cms-admin/actions/f1-sync-now.php. Never echoes, never exit()s.
 *
 * Split into two independently-schedulable syncs (unlike NBA's single
 * games sync) because they're genuinely different cadences: the race
 * calendar barely changes once a season is published, while standings
 * update after every race.
 *
 * F1 seasons run Jan-Dec (unlike football's Aug-May), so "current season"
 * is simply date('Y').
 */

require_once __DIR__ . '/FormulaOneSettings.php';
require_once __DIR__ . '/ApiFormula1Client.php';

/** Self-migrating schema for the four F1 tables — all new, no legacy migration to account for. */
function wpm_ensure_f1_tables(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'f1_races',
        'id INT UNSIGNED NOT NULL PRIMARY KEY,
         season SMALLINT UNSIGNED NOT NULL,
         competition_name VARCHAR(150) NOT NULL,
         competition_location VARCHAR(150) DEFAULT NULL,
         circuit_name VARCHAR(150) DEFAULT NULL,
         type VARCHAR(30) NOT NULL DEFAULT \'Race\',
         status VARCHAR(20) NOT NULL DEFAULT \'Scheduled\',
         race_date DATETIME NOT NULL,
         winner_name VARCHAR(150) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_f1_races_season (season),
         KEY idx_f1_races_date (race_date)'
    );

    cms_ensure_table(
        $pdo,
        'f1_race_podium',
        'race_id INT UNSIGNED NOT NULL,
         position TINYINT UNSIGNED NOT NULL,
         driver_name VARCHAR(150) NOT NULL,
         team_name VARCHAR(150) DEFAULT NULL,
         points DECIMAL(5,1) DEFAULT NULL,
         PRIMARY KEY (race_id, position),
         CONSTRAINT fk_f1_podium_race FOREIGN KEY (race_id) REFERENCES f1_races (id)'
    );

    cms_ensure_table(
        $pdo,
        'f1_driver_standings',
        'season SMALLINT UNSIGNED NOT NULL,
         position TINYINT UNSIGNED NOT NULL,
         driver_name VARCHAR(150) NOT NULL,
         team_name VARCHAR(150) DEFAULT NULL,
         nationality VARCHAR(60) DEFAULT NULL,
         points DECIMAL(6,1) NOT NULL DEFAULT 0,
         wins TINYINT UNSIGNED NOT NULL DEFAULT 0,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (season, position)'
    );

    cms_ensure_table(
        $pdo,
        'f1_constructor_standings',
        'season SMALLINT UNSIGNED NOT NULL,
         position TINYINT UNSIGNED NOT NULL,
         team_name VARCHAR(150) NOT NULL,
         points DECIMAL(6,1) NOT NULL DEFAULT 0,
         wins TINYINT UNSIGNED NOT NULL DEFAULT 0,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (season, position)'
    );
}

/**
 * Sync `f1_races` for the current season (one call — races happen only
 * ~24 times/year, so unlike football/NBA there's no daily-fetch pattern;
 * the whole season calendar is cheap to pull every run) + backfill
 * `f1_race_podium` for any completed "Race"-type session that doesn't
 * have podium data yet (one extra call per newly-completed race, not
 * per run — already-backfilled races are skipped).
 *
 * @return array{ok: bool, skipped_reason: ?string, races_synced: int, podium_backfilled: int, messages: list<string>}
 */
function wpm_sync_f1_races(PDO $pdo): array
{
    $messages = [];
    wpm_ensure_f1_tables($pdo);

    if (!FormulaOneSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur Formula 1 sedang nonaktif.', 'races_synced' => 0, 'podium_backfilled' => 0, 'messages' => $messages];
    }

    $settings = FormulaOneSettings::load($pdo);
    $client = ApiFormula1Client::fromSettings($settings);
    $season = (int) date('Y');

    $result = $client->races(['season' => $season]);
    if (!$result['ok']) {
        return ['ok' => false, 'skipped_reason' => "Gagal ambil kalender race musim {$season}: {$result['error']}", 'races_synced' => 0, 'podium_backfilled' => 0, 'messages' => $messages];
    }

    $raceUpsert = $pdo->prepare(
        'INSERT INTO f1_races (id, season, competition_name, competition_location, circuit_name, type, status, race_date)
         VALUES (:id, :season, :competition_name, :competition_location, :circuit_name, :type, :status, :race_date)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status), race_date = VALUES(race_date),
           competition_name = VALUES(competition_name), circuit_name = VALUES(circuit_name)'
    );

    $racesSynced = 0;
    foreach ($result['data']['response'] ?? [] as $race) {
        if (empty($race['id'])) {
            continue;
        }
        $competition = $race['competition'] ?? [];
        $location = $competition['location'] ?? [];
        $locationParts = array_filter([$location['city'] ?? null, $location['country'] ?? null]);

        $raceUpsert->execute([
            'id' => (int) $race['id'],
            'season' => $season,
            'competition_name' => (string) ($competition['name'] ?? 'Grand Prix'),
            'competition_location' => $locationParts !== [] ? implode(', ', $locationParts) : null,
            'circuit_name' => (string) ($race['circuit']['name'] ?? ''),
            'type' => (string) ($race['type'] ?? 'Race'),
            'status' => (string) ($race['status'] ?? 'Scheduled'),
            'race_date' => date('Y-m-d H:i:s', strtotime((string) ($race['date'] ?? 'now'))),
        ]);
        $racesSynced++;
    }
    $messages[] = "Season {$season}: {$racesSynced} sesi (race/kualifikasi/latihan) di-sync.";

    // Backfill podium for completed "Race" sessions that don't have it yet —
    // one /rankings/races call per race, only for races we haven't already backfilled.
    $needsPodium = $pdo->query(
        "SELECT r.id FROM f1_races r
         LEFT JOIN f1_race_podium p ON p.race_id = r.id AND p.position = 1
         WHERE r.type = 'Race' AND r.status = 'Completed' AND p.race_id IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $podiumUpsert = $pdo->prepare(
        'INSERT INTO f1_race_podium (race_id, position, driver_name, team_name, points)
         VALUES (:race_id, :position, :driver_name, :team_name, :points)
         ON DUPLICATE KEY UPDATE driver_name = VALUES(driver_name), team_name = VALUES(team_name), points = VALUES(points)'
    );
    $winnerUpdate = $pdo->prepare('UPDATE f1_races SET winner_name = :winner_name WHERE id = :id');

    $podiumBackfilled = 0;
    foreach ($needsPodium as $raceId) {
        $rankResult = $client->raceRankings((int) $raceId);
        if (!$rankResult['ok']) {
            $messages[] = "Podium race #{$raceId}: FAILED ({$rankResult['error']})";
            continue;
        }

        $rows = 0;
        foreach ($rankResult['data']['response'] ?? [] as $row) {
            $position = (int) ($row['position'] ?? 0);
            if ($position < 1 || $position > 3) {
                continue;
            }
            $driverName = (string) ($row['driver']['name'] ?? $row['driver'] ?? '');
            $teamName = (string) ($row['team']['name'] ?? $row['team'] ?? '');
            $podiumUpsert->execute([
                'race_id' => (int) $raceId,
                'position' => $position,
                'driver_name' => $driverName,
                'team_name' => $teamName,
                'points' => $row['points'] ?? null,
            ]);
            if ($position === 1 && $driverName !== '') {
                $winnerUpdate->execute(['winner_name' => $driverName, 'id' => $raceId]);
            }
            $rows++;
        }
        if ($rows > 0) {
            $podiumBackfilled++;
            $messages[] = "Podium race #{$raceId}: {$rows} posisi disimpan.";
        }
    }

    return ['ok' => true, 'skipped_reason' => null, 'races_synced' => $racesSynced, 'podium_backfilled' => $podiumBackfilled, 'messages' => $messages];
}

/**
 * Sync `f1_driver_standings` + `f1_constructor_standings` for the current
 * season (2 calls). Independent of wpm_sync_f1_races() so it can run on
 * its own schedule (standings only meaningfully change after a race).
 *
 * @return array{ok: bool, skipped_reason: ?string, drivers_synced: int, constructors_synced: int, messages: list<string>}
 */
function wpm_sync_f1_standings(PDO $pdo): array
{
    $messages = [];
    wpm_ensure_f1_tables($pdo);

    if (!FormulaOneSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur Formula 1 sedang nonaktif.', 'drivers_synced' => 0, 'constructors_synced' => 0, 'messages' => $messages];
    }

    $settings = FormulaOneSettings::load($pdo);
    $client = ApiFormula1Client::fromSettings($settings);
    $season = (int) date('Y');

    $driverUpsert = $pdo->prepare(
        'INSERT INTO f1_driver_standings (season, position, driver_name, team_name, nationality, points, wins)
         VALUES (:season, :position, :driver_name, :team_name, :nationality, :points, :wins)
         ON DUPLICATE KEY UPDATE driver_name = VALUES(driver_name), team_name = VALUES(team_name),
           nationality = VALUES(nationality), points = VALUES(points), wins = VALUES(wins)'
    );

    $driversSynced = 0;
    $driverResult = $client->driverStandings($season);
    if ($driverResult['ok']) {
        foreach ($driverResult['data']['response'] ?? [] as $row) {
            $position = (int) ($row['position'] ?? 0);
            if ($position < 1) {
                continue;
            }
            $driverUpsert->execute([
                'season' => $season,
                'position' => $position,
                'driver_name' => (string) ($row['driver']['name'] ?? $row['driver'] ?? ''),
                'team_name' => (string) ($row['team']['name'] ?? $row['team'] ?? ''),
                'nationality' => (string) ($row['driver']['nationality'] ?? $row['nationality'] ?? ''),
                'points' => $row['points'] ?? 0,
                'wins' => $row['wins'] ?? 0,
            ]);
            $driversSynced++;
        }
        $messages[] = "Klasemen pembalap musim {$season}: {$driversSynced} baris.";
    } else {
        $messages[] = "Klasemen pembalap: FAILED ({$driverResult['error']})";
    }

    $constructorUpsert = $pdo->prepare(
        'INSERT INTO f1_constructor_standings (season, position, team_name, points, wins)
         VALUES (:season, :position, :team_name, :points, :wins)
         ON DUPLICATE KEY UPDATE team_name = VALUES(team_name), points = VALUES(points), wins = VALUES(wins)'
    );

    $constructorsSynced = 0;
    $constructorResult = $client->constructorStandings($season);
    if ($constructorResult['ok']) {
        foreach ($constructorResult['data']['response'] ?? [] as $row) {
            $position = (int) ($row['position'] ?? 0);
            if ($position < 1) {
                continue;
            }
            $constructorUpsert->execute([
                'season' => $season,
                'position' => $position,
                'team_name' => (string) ($row['team']['name'] ?? $row['team'] ?? ''),
                'points' => $row['points'] ?? 0,
                'wins' => $row['wins'] ?? 0,
            ]);
            $constructorsSynced++;
        }
        $messages[] = "Klasemen konstruktor musim {$season}: {$constructorsSynced} baris.";
    } else {
        $messages[] = "Klasemen konstruktor: FAILED ({$constructorResult['error']})";
    }

    return ['ok' => true, 'skipped_reason' => null, 'drivers_synced' => $driversSynced, 'constructors_synced' => $constructorsSynced, 'messages' => $messages];
}
