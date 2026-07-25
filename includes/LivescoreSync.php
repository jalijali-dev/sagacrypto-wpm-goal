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
 * API-Sports' free-tier daily quota error, verbatim across every product
 * in the family (API-Football/API-Basketball/API-Formula-1) — used to
 * decide which cron failures are worth surfacing via
 * *Settings::recordSyncFailure() (24 Jul 2026) vs a routine transient
 * error that isn't worth alarming an admin over.
 */
function wpm_is_quota_error(string $error): bool
{
    return stripos($error, 'request limit') !== false;
}

/**
 * Writes every sync failure (quota or otherwise) to `api_error_log` —
 * that table existed since before this session but nothing ever wrote to
 * it (confirmed empty, 25 Jul 2026 stale-fixture investigation), so a
 * quota outage that froze fixtures mid-match had no timestamped trail to
 * diagnose after the fact. $source identifies which sync/pass failed
 * (e.g. 'football_fixtures_live') so incidents can be narrowed down
 * without parsing free-text messages.
 */
function wpm_log_api_error(PDO $pdo, string $source, string $message): void
{
    try {
        $pdo->prepare('INSERT INTO api_error_log (source, message) VALUES (:source, :message)')
            ->execute(['source' => $source, 'message' => $message]);
    } catch (Throwable $e) {
        // Logging itself must never break a sync run.
    }
}

/**
 * Sync `leagues` + `teams` for every id in tracked_league_ids. Run before
 * the other two — those have a foreign key on leagues/teams existing already.
 *
 * This is by far the most expensive sync per run (2 API calls per tracked
 * league — league info + team roster — 18 calls for 9 leagues), for data
 * that barely changes day to day (league metadata, team rosters). Throttled
 * via LivescoreSettings::secondarySyncDue() — reads sync_secondary_interval
 * from the DB (found unused/decorative until 24 Jul 2026's quota-exhaustion
 * fix) so it only actually hits the API once per configured interval,
 * regardless of how often the external crontab calls this script.
 * $force=true (the admin "Sync Sekarang" button) always bypasses the throttle.
 *
 * @return array{ok: bool, skipped_reason: ?string, leagues_synced: int, teams_synced: int, messages: list<string>}
 */
function wpm_sync_leagues_teams(PDO $pdo, bool $force = false): array
{
    $messages = [];

    if (!LivescoreSettings::isActive($pdo)) {
        return ['ok' => false, 'skipped_reason' => 'Fitur Livescore sedang nonaktif.', 'leagues_synced' => 0, 'teams_synced' => 0, 'messages' => $messages];
    }

    $settings = LivescoreSettings::load($pdo);
    if ($settings['tracked_league_ids'] === []) {
        return ['ok' => false, 'skipped_reason' => 'Belum ada liga yang di-track (tracked_league_ids kosong).', 'leagues_synced' => 0, 'teams_synced' => 0, 'messages' => $messages];
    }

    if (!LivescoreSettings::secondarySyncDue($pdo, $force)) {
        $intervalMinutes = (int) round(($settings['sync_secondary_interval'] ?? $settings['sync_fixtures_interval']) / 60);
        $skipReason = "Throttled — terakhir sync {$settings['last_secondary_sync_at']}, interval {$intervalMinutes} menit belum lewat.";
        return ['ok' => false, 'skipped_reason' => $skipReason, 'leagues_synced' => 0, 'teams_synced' => 0, 'messages' => $messages];
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
    $quotaFailureRecorded = false;

    foreach ($settings['tracked_league_ids'] as $leagueId) {
        $sortOrder++;

        $result = $client->leagues(['id' => $leagueId]);
        if (!$result['ok'] || empty($result['data']['response'][0])) {
            $messages[] = "League {$leagueId}: FAILED ({$result['error']})";
            if ($result['error'] !== '') {
                wpm_log_api_error($pdo, 'football_leagues_teams', "League {$leagueId}: {$result['error']}");
            }
            if (!$quotaFailureRecorded && wpm_is_quota_error($result['error'])) {
                LivescoreSettings::recordSyncFailure($pdo, $result['error']);
                $quotaFailureRecorded = true;
            }
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
            wpm_log_api_error($pdo, 'football_leagues_teams', "Teams for league {$leagueId}: {$teamsResult['error']}");
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

    // Stamped even if individual leagues above failed — this marks "we
    // attempted a pass", same as date_window_checked_at's throttle pattern,
    // so a run that's mostly API errors doesn't retry every single tick.
    LivescoreSettings::markSecondarySynced($pdo);

    // Clears a stale "quota habis" badge left over from an earlier failed
    // run (25 Jul 2026 fix) — without this, last_test_status only ever
    // moved failed -> failed and never back to success on its own.
    if (!$quotaFailureRecorded) {
        LivescoreSettings::recordSyncSuccess($pdo, "Sync otomatis berhasil — {$leaguesSynced} liga, {$teamsSynced} tim disinkronkan.");
    }

    return ['ok' => true, 'skipped_reason' => null, 'leagues_synced' => $leaguesSynced, 'teams_synced' => $teamsSynced, 'messages' => $messages];
}

/**
 * Sync `fixtures` (today + tomorrow, global date fetch, filtered down to
 * tracked leagues locally) + one combined live-fixtures pass, plus a
 * throttled (1x/24h) probe that learns the free-plan's currently allowed
 * /fixtures?date= window so football.php can classify empty results
 * correctly (see LivescoreSettings::getApiDateWindow()).
 *
 * Pass 1 (today+tomorrow, non-live schedule — 2 API calls) is throttled
 * via LivescoreSettings::primarySyncDue(), reading sync_primary_interval
 * from the DB (quota-exhaustion fix, 24 Jul 2026) — upcoming/finished
 * fixtures don't need refreshing on every cron tick. Pass 2 (live) always
 * runs regardless — that's the one pass that actually needs to be fresh,
 * and it's cheap (1 call). $force=true (the admin "Sync Sekarang" button)
 * bypasses the Pass 1 throttle.
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
function wpm_sync_fixtures(PDO $pdo, bool $force = false): array
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
    $quotaFailureThisRun = false;

    // Pass 1 — today + tomorrow, fetched globally (one call per date
    // regardless of tracked-league count). Throttled — non-live schedule
    // data doesn't need refreshing every cron tick (see primarySyncDue()).
    if (LivescoreSettings::primarySyncDue($pdo, $force)) {
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))] as $date) {
            $result = $client->fixtures(['date' => $date]);
            if (!$result['ok']) {
                $messages[] = "Date {$date}: FAILED ({$result['error']})";
                wpm_log_api_error($pdo, 'football_fixtures_pass1', "Date {$date}: {$result['error']}");
                if (wpm_is_quota_error($result['error'])) {
                    LivescoreSettings::recordSyncFailure($pdo, $result['error']);
                    $quotaFailureThisRun = true;
                }
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
        LivescoreSettings::markPrimarySynced($pdo);
    } else {
        $intervalMinutes = (int) round($settings['sync_fixtures_interval'] / 60);
        $messages[] = "Pass 1 (non-live) throttled — terakhir sync {$settings['last_primary_sync_at']}, interval {$intervalMinutes} menit belum lewat.";
    }

    // Pass 2 — live fixtures across every tracked league in one call.
    $liveUpdated = 0;
    $liveResult = $client->liveFixtures($trackedIds);
    if ($liveResult['ok']) {
        $liveUpdated = $upsertBatch($liveResult['data']['response'] ?? []);
        $messages[] = "Live pass: {$liveUpdated} in-play fixtures updated";
    } else {
        $messages[] = "Live pass: FAILED ({$liveResult['error']})";
        wpm_log_api_error($pdo, 'football_fixtures_live', $liveResult['error']);
        if (wpm_is_quota_error($liveResult['error'])) {
            LivescoreSettings::recordSyncFailure($pdo, $liveResult['error']);
            $quotaFailureThisRun = true;
        }
    }

    // Pass 2.5 — finalization safety net (25 Jul 2026 stale-fixture fix).
    // The live pass above only ever UPSERTs whatever API-Football's
    // ?live= endpoint currently returns — once a fixture finishes, the
    // provider drops it from that response entirely instead of reporting
    // it as FT, so nothing in this file ever explicitly closes it out. If
    // a quota outage (or any gap) happens to hit right as a match ends,
    // that fixture is stuck on its last live status (1H/HT/2H/ET/P)
    // forever. This is a pure DB UPDATE — zero API calls, safe to run
    // unconditionally on every invocation regardless of throttling.
    // Threshold: kickoff + 3h covers every normal match length (incl.
    // extra time) with margin; NS is deliberately excluded here — a
    // fixture that never went live isn't safe to assume finished (could
    // be genuinely postponed), see the stale-NS re-verify pass below instead.
    $finalizeStmt = $pdo->prepare(
        "UPDATE fixtures
         SET status_short = 'FT', status_long = 'Match Finished (auto-finalized — stale live status)'
         WHERE status_short IN ('1H','HT','2H','ET','P')
           AND kickoff_at < UTC_TIMESTAMP() - INTERVAL 3 HOUR"
    );
    $finalizeStmt->execute();
    $staleFinalized = $finalizeStmt->rowCount();
    if ($staleFinalized > 0) {
        $messages[] = "Finalization: {$staleFinalized} stale live fixture(s) force-closed to FT (kickoff was 3h+ ago).";
    }

    // Pass 2.6 — stale-NS re-verify (25 Jul 2026). Unlike the live-status
    // case above, a fixture stuck on NS long after kickoff might genuinely
    // be postponed/cancelled, not just under-synced — so instead of
    // assuming FT, re-fetch its real current status. Originally tried
    // /fixtures?ids= (one call for any number of ids) but confirmed live
    // against this account: "Free plans do not have access to the Ids
    // parameter" — so this groups by DATE instead and reuses the plain
    // ?date= call Pass 1 already depends on (known to work on this plan).
    // Capped to 3 distinct stale dates per run so a large backlog can't
    // blow the quota budget by itself; stops early on the first failure
    // (almost certainly quota/date-window, not worth burning more calls).
    $staleNsDates = $pdo->query(
        "SELECT DISTINCT DATE(kickoff_at) AS d FROM fixtures
         WHERE status_short = 'NS' AND kickoff_at < UTC_TIMESTAMP() - INTERVAL 3 HOUR
         ORDER BY d ASC
         LIMIT 3"
    )->fetchAll(PDO::FETCH_COLUMN);

    $staleNsUpdated = 0;
    foreach ($staleNsDates as $staleDate) {
        $staleResult = $client->fixtures(['date' => $staleDate]);
        if (!$staleResult['ok']) {
            $messages[] = "Stale-NS re-verify ({$staleDate}): FAILED ({$staleResult['error']})";
            wpm_log_api_error($pdo, 'football_fixtures_stale_ns', "{$staleDate}: {$staleResult['error']}");
            if (wpm_is_quota_error($staleResult['error'])) {
                LivescoreSettings::recordSyncFailure($pdo, $staleResult['error']);
                $quotaFailureThisRun = true;
            }
            break;
        }
        $staleNsUpdated += $upsertBatch($staleResult['data']['response'] ?? []);
    }
    if ($staleNsUpdated > 0) {
        $messages[] = "Stale-NS re-verify: {$staleNsUpdated} fixture(s) refreshed across " . count($staleNsDates) . " date(s).";
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

    // Clears a stale "quota habis" badge left over from an earlier failed
    // run (25 Jul 2026 fix) — see wpm_sync_leagues_teams()'s equivalent.
    if (!$quotaFailureThisRun) {
        LivescoreSettings::recordSyncSuccess($pdo, "Sync otomatis berhasil — {$fixturesSynced} fixture disinkronkan, {$liveUpdated} live diperbarui.");
    }

    return ['ok' => true, 'skipped_reason' => null, 'fixtures_synced' => $fixturesSynced, 'live_updated' => $liveUpdated, 'teams_upserted' => $teamsUpserted, 'messages' => $messages];
}
