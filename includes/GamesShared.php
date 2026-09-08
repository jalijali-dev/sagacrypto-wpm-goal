<?php
declare(strict_types=1);

/**
 * Shared schema/helpers for "Prediksi & Trivia" (Games Hub game #5,
 * 8 Sep 2026 brief) — the ONLY Games Hub feature with a database
 * backend. Every other game (air-hockey, penalty-kick, quiz-bola,
 * slot-bola) is 100% client-side with in-memory score, deliberately —
 * see docs/DECISIONS.md (8 Sep 2026 entry) for why THIS one is
 * different: a score prediction's outcome isn't knowable until a real
 * match finishes, hours later, which can't be handled purely in a
 * browser tab that might not even be open anymore by then.
 *
 * Included by every api/game-*.php endpoint AND by cron/sync_fixtures.php
 * (for the scoring pass, see includes/GamesScoring.php) — kept as one
 * small shared file rather than duplicated per-endpoint because these
 * are genuinely one feature's backend, not independent products (unlike
 * why e.g. air-hockey.js/penalty-kick.js duplicate their own "synth a
 * tone" pattern instead of sharing one).
 *
 * Identity model: nickname + a client-generated browser_id (UUID),
 * stored in localStorage — no accounts, no login, no phone number (see
 * docs/DECISIONS.md). A new browser/incognito/cleared localStorage is
 * treated as a brand-new player; this is an accepted trade-off, not a
 * bug to fix later.
 */

require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';

/** Idempotent — safe to call on every request (same self-heal pattern as every other cms_ensure_table() caller in this project). */
function wpm_games_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'game_players',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         browser_id VARCHAR(64) NOT NULL,
         nickname VARCHAR(60) NOT NULL,
         total_points INT NOT NULL DEFAULT 0,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_browser_id (browser_id)"
    );

    // `fixture_id` deliberately has NO foreign key to fixtures.id — same
    // reasoning as fixture_streams (see cms-admin/pages/live-streaming.php's
    // comment on that table): includes/LivescoreSync.php overwrites
    // `fixtures` wholesale on every sync, and an FK here risks breaking
    // that sync entirely. Existence/kickoff-time is checked via a
    // read-time JOIN/lookup instead (see api/game-predict.php).
    // `player_id` DOES get a real FK — game_players/game_predictions are
    // both purely owned by this one feature, safe to link directly.
    cms_ensure_table(
        $pdo,
        'game_predictions',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         player_id INT UNSIGNED NOT NULL,
         fixture_id INT NOT NULL,
         predicted_home TINYINT UNSIGNED NOT NULL,
         predicted_away TINYINT UNSIGNED NOT NULL,
         points_awarded TINYINT UNSIGNED NULL DEFAULT NULL,
         scored_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_player_fixture (player_id, fixture_id),
         KEY idx_fixture_id (fixture_id),
         CONSTRAINT fk_game_predictions_player FOREIGN KEY (player_id)
           REFERENCES game_players(id) ON DELETE CASCADE"
    );
}

/** Standard JSON response for every api/game-*.php endpoint — never returns. */
function wpm_games_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Strips tags/collapses whitespace — length/content validation happens separately below. */
function wpm_games_sanitize_nickname(string $raw): string
{
    $n = trim(strip_tags($raw));
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
    return $n;
}

function wpm_games_nickname_is_valid(string $nickname): bool
{
    $len = mb_strlen($nickname);
    return $len >= 2 && $len <= 30;
}

/**
 * Minimal profanity check — no existing filter found anywhere else in
 * this project to reuse (checked includes/ and cms-admin/, confirmed
 * empty), so this is a new, deliberately small, first-pass blocklist.
 * Not exhaustive/not a general content-moderation system — good enough
 * to block the most obvious cases in a short nickname field.
 */
function wpm_games_nickname_is_clean(string $nickname): bool
{
    static $blocklist = [
        'anjing', 'bangsat', 'kontol', 'memek', 'ngentot', 'babi', 'tolol', 'goblok', 'pepek', 'puki',
        'fuck', 'shit', 'bitch', 'asshole', 'nigger', 'cunt',
    ];
    $lower = mb_strtolower($nickname);
    foreach ($blocklist as $word) {
        if (mb_strpos($lower, $word) !== false) {
            return false;
        }
    }
    return true;
}

/**
 * Loose format check for a client-generated id (crypto.randomUUID() or
 * similar) — not a strict UUID-version validator, just enough to reject
 * garbage/oversized input before it touches the database. Length capped
 * at the browser_id column width (64).
 */
function wpm_games_browser_id_is_valid(string $id): bool
{
    return preg_match('/^[a-f0-9-]{16,64}$/i', $id) === 1;
}

/**
 * Upserts a player row and returns its id. The `id = LAST_INSERT_ID(id)`
 * trick in the UPDATE clause makes lastInsertId() reliably return the
 * EXISTING row's id on the update branch too (a plain ON DUPLICATE KEY
 * UPDATE without it can return 0 for the update case, depending on the
 * driver/MySQL version) — avoids a second SELECT round-trip.
 */
function wpm_games_upsert_player(PDO $pdo, string $browserId, string $nickname): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO game_players (browser_id, nickname, created_at, updated_at)
         VALUES (:browser_id, :nickname, NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nickname = VALUES(nickname), updated_at = NOW()'
    );
    $stmt->execute(['browser_id' => $browserId, 'nickname' => $nickname]);
    return (int) $pdo->lastInsertId();
}

/**
 * Basic anti-spam gate — per brief, checked against game_players'
 * updated_at. Note: that column is ALSO touched by the scoring cron's
 * total_points updates (includes/GamesScoring.php), so a genuine POST
 * landing in the same window a batch scoring pass touched this exact
 * player's row is a rare, low-harm false positive (the user just
 * retries a second later) — an accepted trade-off for the simple
 * approach the brief asked for, instead of a dedicated rate-limit
 * table/column.
 */
function wpm_games_rate_limited(PDO $pdo, string $browserId, int $minSeconds = 2): bool
{
    // TIMESTAMPDIFF computed IN MySQL, not PHP (8 Sep 2026 fix) — the
    // original version pulled `updated_at` as a string and did
    // `strtotime($updatedAt . ' UTC')` in PHP, which silently assumed
    // the column was stored in UTC. `updated_at` auto-populates via
    // MySQL's CURRENT_TIMESTAMP, which is written in whatever timezone
    // the DB CONNECTION's `time_zone` session var is set to — on this
    // server that's NOT UTC (same underlying issue as kickoff_at needing
    // CONVERT_TZ elsewhere, e.g. cms-admin/pages/live-streaming.php's
    // 8 Sep 2026 WIB fix). Forcing "UTC" onto an actually-WIB timestamp
    // shifted `$last` by the server's UTC offset, which could make
    // `time() - $last` go NEGATIVE — always less than $minSeconds, so
    // EVERY submit reported as rate-limited regardless of how long the
    // user actually waited (reported by operator: "udah refresh masih
    // kena juga"). Comparing entirely inside MySQL sidesteps the
    // PHP-vs-MySQL timezone mismatch — both sides of the diff come from
    // the same clock/timezone context.
    $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) FROM game_players WHERE browser_id = :browser_id');
    $stmt->execute(['browser_id' => $browserId]);
    $secondsSince = $stmt->fetchColumn();
    if ($secondsSince === false || $secondsSince === null) {
        return false; // never seen this browser_id before — nothing to rate-limit against
    }
    return (int) $secondsSince < $minSeconds;
}
