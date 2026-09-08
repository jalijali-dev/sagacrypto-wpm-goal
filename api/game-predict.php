<?php
declare(strict_types=1);

/**
 * Public endpoint: submit/update one score prediction for the
 * "Prediksi Skor Harian" tab (games/prediksi-trivia/index.php).
 *
 * Upserts the player (browser_id + nickname) and the prediction in one
 * request — a returning browser just re-sends its stored nickname each
 * time, which keeps it in sync if the player ever changes it. Every
 * validation below is server-side and NEVER trusts client input,
 * including the kickoff-time check (a client could disable the <input>
 * via DevTools and submit anyway — this endpoint is the real gate).
 *
 * Request:  POST, JSON or form body — browser_id, nickname, fixture_id,
 *           predicted_home, predicted_away
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
$fixtureId = (int) ($body['fixture_id'] ?? 0);
$predictedHomeRaw = $body['predicted_home'] ?? null;
$predictedAwayRaw = $body['predicted_away'] ?? null;

if (!wpm_games_browser_id_is_valid($browserId)) {
    wpm_games_respond(['success' => false, 'message' => 'browser_id tidak valid.']);
}
if (!wpm_games_nickname_is_valid($nickname)) {
    wpm_games_respond(['success' => false, 'message' => 'Nickname harus 2-30 karakter.']);
}
if (!wpm_games_nickname_is_clean($nickname)) {
    wpm_games_respond(['success' => false, 'message' => 'Nickname mengandung kata yang tidak diperbolehkan.']);
}
if ($fixtureId <= 0) {
    wpm_games_respond(['success' => false, 'message' => 'fixture_id tidak valid.']);
}
if (!is_numeric($predictedHomeRaw) || !is_numeric($predictedAwayRaw)) {
    wpm_games_respond(['success' => false, 'message' => 'Skor tebakan harus angka.']);
}
$predictedHome = (int) $predictedHomeRaw;
$predictedAway = (int) $predictedAwayRaw;
// Upper bound just to reject nonsense input — no realistic football
// scoreline gets anywhere near this.
if ($predictedHome < 0 || $predictedHome > 20 || $predictedAway < 0 || $predictedAway > 20) {
    wpm_games_respond(['success' => false, 'message' => 'Skor tebakan di luar batas wajar (0-20).']);
}

if (wpm_games_rate_limited($pdo, $browserId)) {
    wpm_games_respond(['success' => false, 'message' => 'Terlalu cepat, coba lagi sebentar.'], 429);
}

// Server-side kickoff check — the ONLY check that actually matters for
// preventing cheating (client-side disabling of the input is purely a
// UX nicety, never trusted here). fixtures.kickoff_at is stored
// naive-UTC (see includes/TimeHelpers.php), so a plain UTC compare
// against time() is correct with no timezone conversion needed.
try {
    $stmt = $pdo->prepare('SELECT kickoff_at FROM fixtures WHERE id = :id');
    $stmt->execute(['id' => $fixtureId]);
    $kickoffAt = $stmt->fetchColumn();
} catch (Throwable $e) {
    wpm_games_respond(['success' => false, 'message' => 'Gagal memeriksa jadwal pertandingan.'], 500);
}
if ($kickoffAt === false || $kickoffAt === null) {
    wpm_games_respond(['success' => false, 'message' => 'Pertandingan tidak ditemukan.']);
}
$kickoffTs = strtotime((string) $kickoffAt . ' UTC');
if ($kickoffTs === false || $kickoffTs <= time()) {
    wpm_games_respond(['success' => false, 'message' => 'Pertandingan sudah dimulai, tebakan ditutup.']);
}

try {
    $pdo->beginTransaction();
    $playerId = wpm_games_upsert_player($pdo, $browserId, $nickname);
    // ON DUPLICATE KEY UPDATE on uniq_player_fixture — lets the player
    // change their guess as many times as they want before kickoff
    // without creating extra rows. points_awarded/scored_at are never
    // touched here (they stay whatever they already were — always NULL
    // in practice, since a scored prediction implies kickoff already
    // passed, which the check above already rejects).
    $pdo->prepare(
        'INSERT INTO game_predictions (player_id, fixture_id, predicted_home, predicted_away, created_at)
         VALUES (:player_id, :fixture_id, :predicted_home, :predicted_away, NOW())
         ON DUPLICATE KEY UPDATE predicted_home = VALUES(predicted_home), predicted_away = VALUES(predicted_away)'
    )->execute([
        'player_id' => $playerId,
        'fixture_id' => $fixtureId,
        'predicted_home' => $predictedHome,
        'predicted_away' => $predictedAway,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    wpm_games_respond(['success' => false, 'message' => 'Gagal menyimpan tebakan.'], 500);
}

wpm_games_respond(['success' => true]);
