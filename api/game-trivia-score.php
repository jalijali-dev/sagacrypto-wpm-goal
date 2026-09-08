<?php
declare(strict_types=1);

/**
 * Public endpoint: submit one Trivia Cepat session's final score —
 * called ONCE at the end of a session (question deck exhausted or timer
 * ran out), never per-question. Trivia itself stays 100% client-side
 * during play (same as quiz-bola — no per-answer validation risk worth
 * guarding against for a casual game like this), this endpoint only
 * accumulates the session TOTAL into game_players.total_points.
 *
 * Request:  POST, JSON or form body — browser_id, nickname, points_earned
 * Response: JSON {success:true} or {success:false, message:"..."}
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../includes/GamesShared.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wpm_games_respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    wpm_games_ensure_schema($pdo);
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Schema not ready.'], 500);
}

$raw = file_get_contents('php://input');
$body = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$browserId = trim((string) ($body['browser_id'] ?? ''));
$nickname = wpm_games_sanitize_nickname((string) ($body['nickname'] ?? ''));
$pointsEarnedRaw = $body['points_earned'] ?? null;

if (!wpm_games_browser_id_is_valid($browserId)) {
    wpm_games_respond(['success' => false, 'message' => 'browser_id tidak valid.']);
}
if (!wpm_games_nickname_is_valid($nickname)) {
    wpm_games_respond(['success' => false, 'message' => 'Nickname harus 2-30 karakter.']);
}
if (!wpm_games_nickname_is_clean($nickname)) {
    wpm_games_respond(['success' => false, 'message' => 'Nickname mengandung kata yang tidak diperbolehkan.']);
}
if (!is_numeric($pointsEarnedRaw)) {
    wpm_games_respond(['success' => false, 'message' => 'points_earned tidak valid.']);
}
$pointsEarned = (int) $pointsEarnedRaw;
// Sane upper bound — Trivia Cepat's own max-possible score per session
// (10 questions x 100 base points x 2x max streak multiplier = 2000),
// plus headroom, so a genuine session is never rejected but a scripted
// spam POST can't inflate total_points arbitrarily in one call.
if ($pointsEarned < 0 || $pointsEarned > 5000) {
    wpm_games_respond(['success' => false, 'message' => 'points_earned di luar batas wajar.']);
}

if (wpm_games_rate_limited($pdo, $browserId)) {
    wpm_games_respond(['success' => false, 'message' => 'Terlalu cepat, coba lagi sebentar.'], 429);
}

try {
    $pdo->beginTransaction();
    $playerId = wpm_games_upsert_player($pdo, $browserId, $nickname);
    if ($pointsEarned > 0) {
        // Accumulates — a returning player's new session ADDS to their
        // running total, never overwrites it (per brief).
        $pdo->prepare('UPDATE game_players SET total_points = total_points + :points WHERE id = :id')
            ->execute(['points' => $pointsEarned, 'id' => $playerId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    wpm_games_respond(['success' => false, 'message' => 'Gagal menyimpan skor.'], 500);
}

wpm_games_respond(['success' => true]);
