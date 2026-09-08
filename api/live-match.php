<?php
declare(strict_types=1);

/**
 * Public endpoint: JSON data for ONE live match/custom stream, by
 * `?id=<fixture_id>` or `?custom_id=<fixture_streams.id>` — built for
 * bolabolabola.com/live.php (the PLAYER page) to consume via a
 * server-side HTTP fetch (brief "Live player pindah ke domain
 * terpisah", 9 Sep 2026, revised). The LISTING at sagagoal.com/live
 * stays exactly as it is — this endpoint only feeds the single-match
 * player that moved to bolabolabola.com.
 *
 * *** Replaces an earlier, wrongly-shaped api/live-feed.php from the
 * previous (draft) version of this brief *** — that file returned
 * EVERY currently-live match at once (a listing feed), before the
 * operator clarified that the listing itself isn't moving, only the
 * player. It was never committed, so this is a clean replacement, not
 * a migration. Its query/sanitization/self-contained approach was
 * sound and is reused below, just reshaped from "all live matches" to
 * "one match by id".
 *
 * *** Self-contained on purpose — does NOT require includes/
 * site-bootstrap.php ***. That file unconditionally calls
 * session_start() and pulls in a lot of unrelated public-site machinery
 * that has no business running for a stateless JSON API another
 * domain's SERVER polls — same decision api/game-fixtures-today.php
 * already made (only requires cms-admin/config/database.php). A local
 * `wpm_live_match_respond()` helper is defined below rather than
 * reusing includes/GamesShared.php's wpm_games_respond() — that
 * function is generic enough to technically work, but importing a file
 * literally named "Games" into an unrelated Live Streaming endpoint
 * would be a confusing, misleading dependency for whoever reads this
 * file next; the response helper is 4 lines, cheaper to duplicate than
 * to explain away.
 *
 * Both queries below (fixture mode / custom mode) are copied verbatim
 * from live.php's own ?id=/?custom_id= single-match modes, NOT
 * refactored into a shared function — per the brief's explicit
 * instruction, and consistent with this project's established "small
 * duplication over cross-file coupling" convention (see e.g.
 * assets/games/js/keepie-uppie.js's docblock on why its TEAMS array is
 * a copy of penalty-kick.js's, not a shared import).
 *
 * `embed_code` in the response is the SANITIZED, ready-to-embed
 * `<iframe>` HTML (same shape as includes/site-bootstrap.php's
 * wpm_render_live_embed() produces) — NOT the raw stored embed_code
 * column value. sagagoal.com (the trusted source) runs the one
 * regex-based safety check ONCE, here, and hands bolabolabola.com
 * something already safe to echo directly inside its player frame —
 * rather than shipping the raw stored string and trusting a lighter,
 * separately-hosted codebase to reimplement the same sanitization
 * correctly.
 *
 * CORS: NOT sent — bolabolabola.com/live.php is expected to fetch this
 * SERVER-SIDE (PHP curl, server-to-server), per the brief's recommended
 * approach, so there is no cross-origin browser request to permit. If a
 * future brief adds client-side auto-refresh on bolabolabola.com, THAT
 * is when an `Access-Control-Allow-Origin: https://bolabolabola.com`
 * header (specific origin, never `*`) needs adding here.
 *
 * Auth: none — public, unauthenticated, same as every api/game-*.php
 * endpoint in this project. Rate-limiting/API-key explicitly out of
 * scope for this brief.
 *
 * Request:  GET, exactly one of ?id=<fixture_id> or ?custom_id=<id>
 *           (if somehow both are given, custom_id — the more specific
 *           of the two — wins; not treated as an error)
 * Response: 200 {success:true, data:{...}}
 *           400 {success:false, message:"..."} — missing/invalid id
 *           404 {success:false, message:"..."} — not found or not live
 *           405 {success:false, message:"..."} — wrong HTTP method
 */

require_once __DIR__ . '/../cms-admin/config/database.php';

/** @return never */
function wpm_live_match_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    wpm_live_match_respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

/**
 * Extracts the safe iframe `src` from a stored embed_code value and
 * re-wraps it into a fresh, attribute-controlled <iframe> tag — same
 * validation shape as wpm_render_live_embed() in site-bootstrap.php
 * (accepts either a full <iframe src="..."> snippet or a bare
 * https:// URL), duplicated here (see file docblock for why).
 */
function wpm_live_match_render_embed(string $embedCode): ?string
{
    $embedCode = trim($embedCode);
    if ($embedCode === '') {
        return null;
    }
    $src = null;
    if (preg_match('/<iframe\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>/is', $embedCode, $m) === 1) {
        $src = $m[2];
    } elseif (preg_match('#^https?://\S+$#i', $embedCode) === 1) {
        $src = $embedCode;
    }
    if ($src === null) {
        return null;
    }
    $escaped = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
    return '<iframe src="' . $escaped . '" style="border:none;position:absolute;top:0;left:0;height:100%;width:100%;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
}

$customId = isset($_GET['custom_id']) ? (int) $_GET['custom_id'] : 0;
$fixtureId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($customId <= 0 && $fixtureId <= 0) {
    wpm_live_match_respond(['success' => false, 'message' => 'Sertakan ?id= atau ?custom_id= yang valid.'], 400);
}

try {
    if ($customId > 0) {
        // Custom/manual mode — copied from live.php's $_GET['custom_id'] branch.
        $stmt = $pdo->prepare('SELECT * FROM fixture_streams WHERE id = :id AND is_custom = 1 LIMIT 1');
        $stmt->execute(['id' => $customId]);
        $match = $stmt->fetch();

        if ($match === false || (int) ($match['is_live'] ?? 0) !== 1) {
            wpm_live_match_respond(['success' => false, 'message' => 'Siaran tidak ditemukan atau sedang tidak live.'], 404);
        }

        $data = [
            'is_custom' => 1,
            'custom_id' => $customId,
            'fixture_id' => null,
            'stream_title' => trim((string) ($match['stream_title'] ?? '')),
            'stream_description' => trim((string) ($match['stream_description'] ?? '')),
            'league_name' => trim((string) ($match['custom_league_name'] ?? '')),
            'home_name' => (string) $match['custom_home_name'],
            'away_name' => (string) $match['custom_away_name'],
            'home_logo' => null,
            'away_logo' => null,
            'status_short' => null,
            'elapsed' => null,
            'home_score' => null,
            'away_score' => null,
            'embed_code' => wpm_live_match_render_embed((string) ($match['embed_code'] ?? '')),
        ];
    } else {
        // Real-fixture mode — copied from live.php's $_GET['id'] branch,
        // plus home_score/away_score (live.php's own query doesn't select
        // those — it only needs the embed player, not a scoreline — but
        // this API is asked to expose them for the player page to show).
        $stmt = $pdo->prepare(
            "SELECT fs.*, f.status_short, f.elapsed, f.home_score, f.away_score, f.kickoff_at,
                    l.name AS league_name, ht.name AS home_name, at.name AS away_name,
                    ht.logo AS home_logo, at.logo AS away_logo
             FROM fixtures f
             JOIN leagues l ON l.id = f.league_id
             JOIN teams ht ON ht.id = f.home_team_id
             JOIN teams at ON at.id = f.away_team_id
             LEFT JOIN fixture_streams fs ON fs.fixture_id = f.id AND fs.is_custom = 0
             WHERE f.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $fixtureId]);
        $match = $stmt->fetch();

        if ($match === false || (int) ($match['is_live'] ?? 0) !== 1) {
            wpm_live_match_respond(['success' => false, 'message' => 'Pertandingan tidak ditemukan atau sedang tidak live.'], 404);
        }

        $data = [
            'is_custom' => 0,
            'custom_id' => null,
            'fixture_id' => $fixtureId,
            'stream_title' => trim((string) ($match['stream_title'] ?? '')),
            'stream_description' => trim((string) ($match['stream_description'] ?? '')),
            'league_name' => trim((string) ($match['league_name'] ?? '')),
            'home_name' => (string) $match['home_name'],
            'away_name' => (string) $match['away_name'],
            'home_logo' => $match['home_logo'] !== null ? (string) $match['home_logo'] : null,
            'away_logo' => $match['away_logo'] !== null ? (string) $match['away_logo'] : null,
            'status_short' => $match['status_short'] !== null ? (string) $match['status_short'] : null,
            'elapsed' => $match['elapsed'] !== null ? (int) $match['elapsed'] : null,
            'home_score' => $match['home_score'] !== null ? (int) $match['home_score'] : null,
            'away_score' => $match['away_score'] !== null ? (int) $match['away_score'] : null,
            'embed_code' => wpm_live_match_render_embed((string) ($match['embed_code'] ?? '')),
        ];
    }
} catch (Throwable $e) {
    wpm_live_match_respond(['success' => false, 'message' => 'Could not load match.'], 500);
}

wpm_live_match_respond(['success' => true, 'data' => $data]);
