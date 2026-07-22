<?php
declare(strict_types=1);

/**
 * Shared Livescore/API-Football sync logic — the actual work behind
 * cron/sync_leagues_teams.php and cron/sync_fixtures.php. Pulled out here
 * so both the CLI cron scripts AND cms-admin/actions/livescore-sync-now.php
 * (the admin "Sync Sekarang" button) call the exact same code instead of
 * duplicating it. Each function returns a structured summary (never
 * echoes, never exit()s) so callers can format progress however they
 * need — the CLI wrappers print the messages, the AJAX action turns them
 * into JSON.
 *
 * Standings (wpm_sync_standings()) was removed entirely — API-Football's
 * free plan blocks /standings for every tracked league with no
 * workaround (unlike fixtures/teams, standings data isn't embedded
 * anywhere else), and the product direction moved away from a
 * league-standings feature (see the "Olahraga" selector pivot).
 */

require_once __DIR__ . '/../cms-admin/includes/functions.php'; // cms_slugify()
require_once __DIR__ . '/LivescoreSettings.php';
require_once __DIR__ . '/ApiFootballClient.php';

/**
 * Sync `leagues` + `teams` for every id in tracked_league_ids. Run before
 * the other two — those have a foreign key on leagues/teams existing already.
 *
 * @return array{ok: bool, skipped_reason: ?string, leagues_synced: int, teams_synced: int, messages: list<string>}
 */
function wpm_sync_leagues_teams(PDO $pdo): array
{
    $messages = [];

    if (!LivescoreSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur Livescore sedang nonaktif.', 'leagues_synced' => 0, 'teams_synced' => 0, 'messages' => $messages];
    }

    $settings = LivescoreSettings::load($pdo);
    if ($settings['tracked_league_ids'] === []) {
        return ['ok' => false, 'skipped_reason' => 'Belum ada liga yang di-track (tracked_league_ids kosong).', 'leagues_synced' => 0, 'teams_synced' => 0, 'messages' => $messages];
    }

    $client = ApiFootballClient::fromSettings($settings);

    $leagueUpsert = $pdo->prepare(
        'INSERT INTO leagues (id, name, slug, type, country, country_code, logo, flag, current_season, is_active, sort_order)
         VALUES (:id, :name, :slug, :type, :country, :country_code, :logo, :flag, :season, 1, :sort_order)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name), slug = VALUES(slug), type = VALUES(type), country = VALUES(country),
           country_code = VALUES(country_code), logo = VALUES(logo), flag = VALUES(flag),
           current_season = VALUES(current_season)'
    );

    $teamUpsert = $pdo->prepare(
        'INSERT INTO teams (id, name, code, country, founded, logo, venue_name, venue_city, is_active)
         VALUES (:id, :name, :code, :country, :founded, :logo, :venue_name, :venue_city, 1)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name), code = VALUES(code), country = VALUES(country), founded = VALUES(founded),
           logo = VALUES(logo), venue_name = VALUES(venue_name), venue_city = VALUES(venue_city)'
    );

    $leaguesSynced = 0;
    $teamsSynced = 0;
    $sortOrder = 0;

    foreach ($settings['tracked_league_ids'] as $leagueId) {
        $sortOrder++;

        $result = $client->leagues(['id' => $leagueId]);
        if (!$result['ok'] || empty($result['data']['response'][0])) {
            $messages[] = "League {$leagueId}: FAILED ({$result['error']})";
            continue;
        }

        $entry = $result['data']['response'][0];
        $leagueInfo = $entry['league'] ?? [];
        $countryInfo = $entry['country'] ?? [];
        $leagueName = (string) ($leagueInfo['name'] ?? ('League ' . $leagueId));

        // API-Football lists every season it has data for — find the one
        // actually marked current rather than assuming date('Y'), since a
        // league's "2024" season can run Aug 2024 -> May 2025. Skip the
        // whole league (like teams too) if it has no active season now.
        $season = null;
        foreach ($entry['seasons'] ?? [] as $seasonEntry) {
            if (!empty($seasonEntry['current'])) {
                $season = (int) $seasonEntry['year'];
                break;
            }
        }
        if ($season === null) {
            $messages[] = "League {$leagueId} ({$leagueName}): no current season, skipped";
            continue;
        }

        $leagueUpsert->execute([
            'id' => $leagueId,
            'name' => $leagueName,
            'slug' => cms_slugify($leagueName) ?: ('league-' . $leagueId),
            'type' => (string) ($leagueInfo['type'] ?? ''),
            'country' => (string) ($countryInfo['name'] ?? ''),
            'country_code' => (string) ($countryInfo['code'] ?? ''),
            'logo' => (string) ($leagueInfo['logo'] ?? ''),
            'flag' => (string) ($countryInfo['flag'] ?? ''),
            'season' => $season,
            'sort_order' => $sortOrder,
        ]);
        $leaguesSynced++;
        $messages[] = "League {$leagueId} ({$leagueName}): OK";

        $teamsResult = $client->teams($leagueId, $season);
        if (!$teamsResult['ok']) {
            $messages[] = "  Teams for league {$leagueId}: FAILED ({$teamsResult['error']})";
            continue;
        }

        $teamCount = 0;
        foreach ($teamsResult['data']['response'] ?? [] as $teamEntry) {
            $team = $teamEntry['team'] ?? [];
            $venue = $teamEntry['venue'] ?? [];
            if (empty($team['id'])) {
                continue;
            }
            $teamUpsert->execute([
                'id' => (int) $team['id'],
                'name' => (string) ($team['name'] ?? ''),
                'code' => (string) ($team['code'] ?? ''),
                'country' => (string) ($team['country'] ?? ''),
                'founded' => $team['founded'] ?? null,
                'logo' => (string) ($team['logo'] ?? ''),
                'venue_name' => (string) ($venue['name'] ?? ''),
                'venue_city' => (string) ($venue['city'] ?? ''),
            ]);
            $teamCount++;
        }
        $teamsSynced += $teamCount;
        $messages[] = "  Teams for league {$leagueId}: {$teamCount} synced";
    }

    return ['ok' => true, 'skipped_reason' => null, 'leagues_synced' => $leaguesSynced, 'teams_synced' => $teamsSynced, 'messages' => $messages];
}

/**
 * Sync `fixtures` (today + tomorrow, global date fetch, filtered down to
 * tracked leagues locally) + one combined live-fixtures pass, plus a
 * throttled (1x/24h) probe that learns the free-plan's currently allowed
 * /fixtures?date= window so livescore.php can classify empty results
 * correctly (see LivescoreSettings::getApiDateWindow()).
 *
 * Also upserts `teams` (id/name/logo only) straight from the team data
 * API-Football embeds in every fixture entry — /teams?league=&season= is
 * blocked on free plans for the current season (confirmed: every tracked
 * league fails with "Free plans do not have access to this season"), so
 * this is now the only reliable source of team names/logos until the
 * plan is upgraded. Richer fields (venue, founded, country, code) stay
 * NULL until a working /teams call can fill them in — never overwritten
 * with NULL if already populated, since the upsert only touches
 * name/logo on conflict.
 *
 * @return array{ok: bool, skipped_reason: ?string, fixtures_synced: int, live_updated: int, teams_upserted: int, messages: list<string>}
 */
function wpm_sync_fixtures(PDO $pdo): array
{
    $messages = [];

    if (!LivescoreSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur Livescore sedang nonaktif.', 'fixtures_synced' => 0, 'live_updated' => 0, 'teams_upserted' => 0, 'messages' => $messages];
    }

    $settings = LivescoreSettings::load($pdo);
    if ($settings['tracked_league_ids'] === []) {
        return ['ok' => false, 'skipped_reason' => 'Belum ada liga yang di-track (tracked_league_ids kosong).', 'fixtures_synced' => 0, 'live_updated' => 0, 'teams_upserted' => 0, 'messages' => $messages];
    }

    $client = ApiFootballClient::fromSettings($settings);
    $trackedIds = $settings['tracked_league_ids'];

    $teamUpsert = $pdo->prepare(
        'INSERT INTO teams (id, name, logo, is_active)
         VALUES (:id, :name, :logo, 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), logo = VALUES(logo)'
    );
    $teamsUpserted = 0;
    // Avoids re-upserting the same team on every fixture within one run
    // (a team plays several fixtures across the date + live passes).
    $seenTeamIds = [];

    $fixtureUpsert = $pdo->prepare(
        'INSERT INTO fixtures (
            id, league_id, season, round, kickoff_at, timezone, venue_name, referee,
            status_short, status_long, elapsed, home_team_id, away_team_id,
            home_score, away_score, ht_home_score, ht_away_score
         ) VALUES (
            :id, :league_id, :season, :round, :kickoff_at, :timezone, :venue_name, :referee,
            :status_short, :status_long, :elapsed, :home_team_id, :away_team_id,
            :home_score, :away_score, :ht_home_score, :ht_away_score
         )
         ON DUPLICATE KEY UPDATE
           round = VALUES(round), kickoff_at = VALUES(kickoff_at), venue_name = VALUES(venue_name),
           referee = VALUES(referee), status_short = VALUES(status_short), status_long = VALUES(status_long),
           elapsed = VALUES(elapsed), home_score = VALUES(home_score), away_score = VALUES(away_score),
           ht_home_score = VALUES(ht_home_score), ht_away_score = VALUES(ht_away_score)'
    );

    /**
     * Filters to fixtures whose league is one of ours before upserting —
     * the date-range pass fetches globally (no league param, cheapest on
     * quota) so this is where we narrow back down to tracked leagues.
     *
     * @param array<int, array<string, mixed>> $fixtureEntries
     */
    $upsertBatch = static function (array $fixtureEntries) use ($fixtureUpsert, $teamUpsert, $trackedIds, &$seenTeamIds, &$teamsUpserted): int {
        $count = 0;
        foreach ($fixtureEntries as $entry) {
            $fixture = $entry['fixture'] ?? [];
            $league = $entry['league'] ?? [];
            $teams = $entry['teams'] ?? [];
            $goals = $entry['goals'] ?? [];
            $halftime = $entry['score']['halftime'] ?? [];
            $status = $fixture['status'] ?? [];

            if (empty($fixture['id']) || empty($teams['home']['id']) || empty($teams['away']['id'])) {
                continue;
            }
            if (!in_array((int) ($league['id'] ?? 0), $trackedIds, true)) {
                continue;
            }

            foreach (['home', 'away'] as $side) {
                $teamId = (int) $teams[$side]['id'];
                if (isset($seenTeamIds[$teamId])) {
                    continue;
                }
                $teamUpsert->execute([
                    'id' => $teamId,
                    'name' => (string) ($teams[$side]['name'] ?? ''),
                    'logo' => (string) ($teams[$side]['logo'] ?? ''),
                ]);
                $seenTeamIds[$teamId] = true;
                $teamsUpserted++;
            }

            $fixtureUpsert->execute([
                'id' => (int) $fixture['id'],
                'league_id' => (int) ($league['id'] ?? 0),
                'season' => (int) ($league['season'] ?? 0),
                'round' => (string) ($league['round'] ?? ''),
                'kickoff_at' => date('Y-m-d H:i:s', strtotime((string) ($fixture['date'] ?? 'now'))),
                'timezone' => (string) ($fixture['timezone'] ?? 'UTC'),
                'venue_name' => (string) ($fixture['venue']['name'] ?? ''),
                'referee' => (string) ($fixture['referee'] ?? ''),
                'status_short' => (string) ($status['short'] ?? 'NS'),
                'status_long' => (string) ($status['long'] ?? ''),
                'elapsed' => $status['elapsed'] ?? null,
                'home_team_id' => (int) $teams['home']['id'],
                'away_team_id' => (int) $teams['away']['id'],
                'home_score' => $goals['home'] ?? null,
                'away_score' => $goals['away'] ?? null,
                'ht_home_score' => $halftime['home'] ?? null,
                'ht_away_score' => $halftime['away'] ?? null,
            ]);
            $count++;
        }
        return $count;
    };

    $fixturesSynced = 0;

    // Pass 1 — today + tomorrow, fetched globally (one call per date
    // regardless of tracked-league count).
    foreach ([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))] as $date) {
        $result = $client->fixtures(['date' => $date]);
        if (!$result['ok']) {
            $messages[] = "Date {$date}: FAILED ({$result['error']})";
            // Free (no extra call): if even today/tomorrow just fell
            // outside the plan's date window, capture it.
            $parsedWindow = ApiFootballClient::parseDateWindowError($result['error']);
            if ($parsedWindow !== null) {
                LivescoreSettings::saveApiDateWindow($pdo, $parsedWindow['start'], $parsedWindow['end']);
                $messages[] = "Date window updated from Pass 1 failure: {$parsedWindow['start']} to {$parsedWindow['end']}";
            }
            continue;
        }
        $n = $upsertBatch($result['data']['response'] ?? []);
        $fixturesSynced += $n;
        $messages[] = "Date {$date}: {$n} tracked-league fixtures synced";
    }

    // Pass 2 — live fixtures across every tracked league in one call.
    $liveUpdated = 0;
    $liveResult = $client->liveFixtures($trackedIds);
    if ($liveResult['ok']) {
        $liveUpdated = $upsertBatch($liveResult['data']['response'] ?? []);
        $messages[] = "Live pass: {$liveUpdated} in-play fixtures updated";
    } else {
        $messages[] = "Live pass: FAILED ({$liveResult['error']})";
    }

    if ($teamsUpserted > 0) {
        $messages[] = "{$teamsUpserted} team(s) upserted from embedded fixture data (name/logo only).";
    }

    // Pass 3 — learn/refresh the free-plan date-accessibility window,
    // throttled to once per 24h regardless of how often this runs.
    $window = LivescoreSettings::getApiDateWindow($pdo);
    $needsProbe = $window['checked_at'] === null || (time() - strtotime($window['checked_at'])) > 86400;

    if ($needsProbe) {
        $probeDate = date('Y-m-d', strtotime('+90 days'));
        $probeResult = $client->fixtures(['date' => $probeDate]);
        $parsedWindow = ApiFootballClient::parseDateWindowError($probeResult['error'] ?? '');

        if ($parsedWindow !== null) {
            LivescoreSettings::saveApiDateWindow($pdo, $parsedWindow['start'], $parsedWindow['end']);
            $messages[] = "Date window probe: learned {$parsedWindow['start']} to {$parsedWindow['end']}";
        } elseif ($probeResult['ok']) {
            LivescoreSettings::saveApiDateWindow($pdo, null, null);
            $messages[] = "Date window probe: {$probeDate} succeeded — no date restriction detected.";
        } else {
            $messages[] = "Date window probe: inconclusive ({$probeResult['error']}), will retry next run.";
        }
    }

    return ['ok' => true, 'skipped_reason' => null, 'fixtures_synced' => $fixturesSynced, 'live_updated' => $liveUpdated, 'teams_upserted' => $teamsUpserted, 'messages' => $messages];
}
