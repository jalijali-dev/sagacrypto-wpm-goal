<?php
declare(strict_types=1);

/**
 * WIB (Asia/Jakarta, UTC+7) display conversion for match/game/race times
 * ONLY — fixtures.kickoff_at, nba_games.game_date, and f1_races.race_date
 * are all stored as naive UTC datetimes (confirmed: both PHP's default tz
 * and MySQL's server tz are UTC on this host, so `strtotime()`/`date()`
 * on these columns previously rendered raw UTC clock time with no
 * conversion — the "23:30 UTC shown as 23:30" bug).
 *
 * Deliberately a separate helper from wpm_format_date() (site-bootstrap.php)
 * — that one is shared by article/page timestamps, which are a different,
 * not-in-scope concern and must keep rendering as before.
 *
 * Storage stays UTC (correct practice) — only display/day-grouping
 * converts. SQL day-grouping should use CONVERT_TZ(col, '+00:00', '+07:00')
 * (fixed-offset form works without MySQL's named timezone tables loaded).
 */

const WPM_MATCH_TZ = 'Asia/Jakarta';

/** Current moment in WIB — use instead of date()/time() (PHP default tz is UTC) for any match/game/race "now" logic. */
function wpm_now_wib(): DateTime
{
    return new DateTime('now', new DateTimeZone(WPM_MATCH_TZ));
}

/** Today's date in WIB (Y-m-d) — use instead of date('Y-m-d') for date-strip defaults / "is_today" checks, since WIB is 7h ahead of the server's UTC "today". */
function wpm_today_wib(): string
{
    return wpm_now_wib()->format('Y-m-d');
}

/** Converts a stored UTC-naive match/game/race datetime string to a WIB DateTime. Null on empty/unparseable input. */
function wpm_match_time_wib(?string $utcValue): ?DateTime
{
    if ($utcValue === null || $utcValue === '') {
        return null;
    }
    try {
        $dt = new DateTime($utcValue, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone(WPM_MATCH_TZ));
    return $dt;
}

/** Formats a stored UTC match/game/race datetime string in WIB. */
function wpm_format_match_time(?string $utcValue, string $fmt = 'H:i'): string
{
    $dt = wpm_match_time_wib($utcValue);
    return $dt !== null ? $dt->format($fmt) : '—';
}
