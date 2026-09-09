<?php
declare(strict_types=1);

/**
 * Prediksi & Trivia — Games Hub's fifth game (8 Sep 2026, brief "Games
 * Hub #5: Prediksi & Trivia"). Same standalone-page approach as the
 * other 4 games (see games/air-hockey/index.php's docblock) — no
 * site-header.php/site-footer.php, own <head>, own CSS/JS.
 *
 * *** THE ONLY GAME WITH A DATABASE BACKEND — read before editing ***
 * air-hockey/penalty-kick/quiz-bola/slot-bola are all 100% client-side,
 * in-memory score. This one ISN'T, on purpose: a score prediction's
 * outcome (win/lose points) isn't knowable until the real match finishes
 * hours later — that can't be handled in a browser tab that might not
 * even be open by then. See docs/DECISIONS.md (8 Sep 2026 entry) for
 * the full reasoning, and includes/GamesShared.php's docblock for the
 * identity model (nickname + localStorage browser_id, no accounts).
 *
 * One game, two tabs (operator decision, confirmed before this brief was
 * written — not two separate games): "Prediksi Skor Harian" and
 * "Trivia Cepat". This file SSRs today's fixture list (server-side
 * query below) for a fast/no-JS-still-shows-the-schedule initial paint;
 * assets/games/js/prediksi-trivia.js then fetches api/game-fixtures-
 * today.php (which also knows this browser's OWN predictions, via
 * ?browser_id=, something this PHP request has no way to know — pure
 * localStorage identity, no cookies) and hydrates each card's
 * input/locked/result state from that response. Trivia Cepat is pure
 * client-side during play (own question bank, see prediksi-trivia.js's
 * docblock for why it's a copy of quiz-bola.js's bank rather than a
 * shared import — same "each game file stays independent" convention
 * as every other game here), only submitting once at session end.
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';
require_once __DIR__ . '/../../includes/TimeHelpers.php';

$pageTitle = 'Prediksi & Trivia — Sagagoal Games';
$pageDescription = 'Tebak skor pertandingan hari ini dan jawab kuis cepat seputar sepak bola. Kumpulkan poin, naik ke leaderboard — gratis, tanpa akun.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/prediksi-trivia.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/prediksi-trivia.js') ?: 1;

// Browser tab favicon (same pattern as the other 4 games/* pages — see
// games/index.php's comment for why this deliberately does NOT use
// wpm_image() on a nested page).
$wpmSiteSettings = wpm_site_settings($pdo);
$wpmFaviconRaw = trim((string) ($wpmSiteSettings['favicon_path'] ?? ''));
if ($wpmFaviconRaw === '') {
    $wpmFaviconRaw = trim((string) ($wpmSiteSettings['logo_path'] ?? ''));
}
$wpmFaviconUrl = $wpmFaviconRaw !== '' ? $wpmFaviconRaw : null;

// ---- SSR: fixtures kicking off TODAY through +2 days (WIB) — widened
// 9 Sep 2026 from "today only" per operator request, so predictions can
// be filled in up to 2 days ahead instead of only on match day itself.
// Still the same day-window CONVERT_TZ pattern as football.php, just a
// BETWEEN range instead of a single-day equality check. Only team
// names/kickoff time are needed for this initial render —
// scores/lock-state/my-own-prediction all come from the JS fetch to
// api/game-fixtures-today.php afterward (this PHP request has no
// browser_id to look predictions up with anyway, since identity lives
// purely in localStorage, not a cookie/session). Variable name kept as
// $todaysFixtures despite the widened range — renaming ripples into the
// template loop below for no real benefit. ----
$todaysFixtures = [];
try {
    $wibToday = wpm_today_wib();
    $wibUntil = (new DateTime($wibToday, new DateTimeZone(WPM_MATCH_TZ)))->modify('+2 days')->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT f.id, f.kickoff_at,
                ht.name AS home_name, at.name AS away_name,
                l.name AS league_name
         FROM fixtures f
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         JOIN leagues l ON l.id = f.league_id
         WHERE DATE(CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00')) BETWEEN :today AND :until
         ORDER BY f.kickoff_at ASC"
    );
    $stmt->execute(['today' => $wibToday, 'until' => $wibUntil]);
    $todaysFixtures = $stmt->fetchAll();
} catch (Throwable $e) {
    $todaysFixtures = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <!-- NOT wpm_base_href()/wpm_site_url() — see games/index.php's <base>
         comment for why (that helper only works for flat root-level
         scripts; this file is two directories below the root, same as
         the other 4 games/* pages). -->
    <base href="../../">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <meta name="robots" content="index, follow">
    <?php if ($wpmFaviconUrl !== null) : ?>
        <link rel="icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/games/css/games-landing.css?v=<?= (int) $cssVer ?>">
    <link rel="stylesheet" href="assets/games/css/prediksi-trivia.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-pt">
    <div class="wpm-pt-topbar">
        <a class="wpm-games-back wpm-pt-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-pt-topbar__title">Prediksi &amp; Trivia</span>
        <div class="wpm-pt-topbar__right">
            <!-- Sound only matters for Trivia Cepat's feedback tones —
                 same mute-button pattern as the other 4 games, audio
                 only ever starts inside a real click handler. -->
            <button type="button" class="wpm-pt-mute-btn" id="pt-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-pt-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-pt-stage">
        <!-- Nickname gate (8 Sep 2026) — shown once, only when
             localStorage has no pt_nickname yet (see prediksi-trivia.js).
             No server round-trip to check this — it's purely a
             localStorage read on page load, so this panel starts
             `hidden` and JS un-hides it if needed (never the other way
             around, so a JS error fails toward "form hidden", not
             "form blocking the whole page"). -->
        <div class="wpm-pt-panel wpm-pt-nickname-gate" id="pt-nickname-gate" hidden>
            <h1 class="wpm-pt-panel__title">Siapa nama kamu?</h1>
            <p class="wpm-pt-panel__hint">Nickname buat tebakan &amp; leaderboard kamu — disimpan di device ini, bukan akun. 2-30 karakter.</p>
            <input type="text" class="wpm-pt-nickname-input" id="pt-nickname-input" maxlength="30" placeholder="Nickname kamu" autocomplete="off">
            <p class="wpm-pt-nickname-error" id="pt-nickname-error" hidden></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="pt-nickname-save-btn">Simpan &amp; Mulai</button>
        </div>

        <div class="wpm-pt-main" id="pt-main" hidden>
            <p class="wpm-pt-whoami" id="pt-whoami"></p>

            <div class="wpm-pt-tabs" role="tablist">
                <button type="button" class="wpm-pt-tab is-active" id="pt-tab-predict-btn" role="tab" aria-selected="true">⚽ Prediksi Skor Harian</button>
                <button type="button" class="wpm-pt-tab" id="pt-tab-trivia-btn" role="tab" aria-selected="false">⚡ Trivia Cepat</button>
            </div>

            <!-- ---- Tab 1: Prediksi Skor Harian ---- -->
            <section class="wpm-pt-tabpanel" id="pt-tabpanel-predict" role="tabpanel">
                <p class="wpm-pt-hint">Tebak skor akhir sebelum kickoff — bisa diisi dari 2 hari sebelum pertandingan. Tebakan persis = 5 poin, hasil bener (menang/kalah/seri) = 2 poin. Bisa diubah kapan saja sebelum pertandingan mulai.</p>
                <?php if ($todaysFixtures === []) : ?>
                    <p class="wpm-pt-empty">Belum ada jadwal pertandingan buat 2 hari ke depan.</p>
                <?php else : ?>
                    <div class="wpm-pt-match-list" id="pt-match-list">
                        <?php foreach ($todaysFixtures as $fx) : ?>
                            <div class="wpm-pt-match" data-fixture-id="<?= (int) $fx['id'] ?>">
                                <div class="wpm-pt-match__meta">
                                    <span class="wpm-pt-match__league"><?= wpm_esc((string) $fx['league_name']) ?></span>
                                    <span class="wpm-pt-match__time"><?= wpm_esc(wpm_format_match_time($fx['kickoff_at'], 'd/m')) ?> &middot; <?= wpm_esc(wpm_format_match_time($fx['kickoff_at'], 'H:i')) ?> WIB</span>
                                </div>
                                <div class="wpm-pt-match__teams">
                                    <span class="wpm-pt-match__team"><?= wpm_esc((string) $fx['home_name']) ?></span>
                                    <span class="wpm-pt-match__vs">vs</span>
                                    <span class="wpm-pt-match__team"><?= wpm_esc((string) $fx['away_name']) ?></span>
                                </div>
                                <div class="wpm-pt-match__predict-area" data-role="predict-area">
                                    <span class="wpm-pt-match__loading">Memuat…</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ---- Tab 2: Trivia Cepat ---- -->
            <section class="wpm-pt-tabpanel" id="pt-tabpanel-trivia" role="tabpanel" hidden>
                <div class="wpm-pt-panel" id="pt-trivia-start">
                    <h2 class="wpm-pt-panel__title">Trivia Cepat</h2>
                    <p class="wpm-pt-panel__hint">10 soal, 10 detik per soal. Jawab benar berturut-turut buat multiplier poin lebih besar!</p>
                    <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="pt-trivia-start-btn">Mulai Trivia</button>
                </div>

                <div class="wpm-pt-trivia-board" id="pt-trivia-board" hidden>
                    <div class="wpm-pt-trivia-scoreboard">
                        <div class="wpm-pt-trivia-scoreboard__side">
                            <span class="wpm-pt-trivia-scoreboard__label">Soal</span>
                            <span class="wpm-pt-trivia-scoreboard__score" id="pt-trivia-question-count">1/10</span>
                        </div>
                        <span class="wpm-pt-streak-badge" id="pt-trivia-streak-badge" hidden>🔥 <span id="pt-trivia-streak-value"></span></span>
                        <div class="wpm-pt-trivia-scoreboard__side">
                            <span class="wpm-pt-trivia-scoreboard__label">Skor</span>
                            <span class="wpm-pt-trivia-scoreboard__score" id="pt-trivia-score">0</span>
                        </div>
                    </div>
                    <div class="wpm-pt-timer-track" aria-hidden="true">
                        <div class="wpm-pt-timer-fill" id="pt-trivia-timer-fill"></div>
                    </div>
                    <div class="wpm-pt-trivia-card">
                        <p class="wpm-pt-trivia-question" id="pt-trivia-question-text"></p>
                        <div class="wpm-pt-trivia-options" id="pt-trivia-options" role="group" aria-label="Pilihan jawaban"></div>
                    </div>
                </div>

                <div class="wpm-pt-panel wpm-pt-panel--overlay" id="pt-trivia-end" hidden>
                    <h2 class="wpm-pt-panel__title">Selesai!</h2>
                    <p class="wpm-pt-panel__score" id="pt-trivia-end-score"></p>
                    <p class="wpm-pt-panel__hint" id="pt-trivia-end-breakdown"></p>
                    <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="pt-trivia-play-again-btn">Main Lagi</button>
                </div>
            </section>

            <!-- ---- Cara main (8 Sep 2026, operator request) — nempel di
                 bawah kedua tab (bukan cuma di tab Prediksi), sengaja
                 pakai <ol> singkat karena user sempet nanya di chat "cara
                 maennya besok cek lagi hasilnya gitu?" — jawabannya iya,
                 jadi ini nulisin urutan itu eksplisit biar user lain gak
                 perlu nanya lagi. Murni teks statis, gak nyambung ke
                 logic apapun. ---- -->
            <div class="wpm-pt-howto">
                <h3 class="wpm-pt-howto__title">Cara Main</h3>
                <ol class="wpm-pt-howto__list">
                    <li>Isi tebakan skor buat pertandingan yang belum kickoff, klik "Kunci Tebakan".</li>
                    <li>Mau ganti tebakan? Boleh, klik "Ubah Tebakan" — asal belum lewat waktu kickoff.</li>
                    <li>Begitu kickoff lewat, tebakan otomatis terkunci, gak bisa diubah lagi.</li>
                    <li>Setelah pertandingan selesai, poin otomatis dihitung — gak perlu ngapa-ngapain, cukup balik lagi ke halaman ini buat cek hasilnya (biasanya beberapa jam/besoknya, tergantung jadwal pertandingan).</li>
                    <li>Skor persis = 5 poin. Tebak menang/kalah/seri-nya doang bener (skor beda) = 2 poin. Salah total = 0 poin.</li>
                    <li>Main Trivia Cepat kapan aja buat nambah poin — hasilnya otomatis gabung ke total poin & leaderboard yang sama.</li>
                </ol>
            </div>

            <!-- ---- Tebakan Saya (9 Sep 2026, operator request) — recap
                 box of just MY OWN saved predictions across the whole
                 H+2 window above, so I don't have to scroll/hunt through
                 the full fixture list to see what I've already guessed.
                 Populated client-side from the SAME api/game-fixtures-
                 today.php response loadFixtures() already fetches for
                 the main list — no extra request. Editable inline here
                 too (reuses the exact same submitPrediction() as the
                 main list's form), as long as the fixture isn't locked
                 yet — see prediksi-trivia.js's renderMyPredictions(). ---->
            <button type="button" class="wpm-pt-leaderboard-toggle" id="pt-my-predictions-toggle" aria-expanded="false">📝 Tebakan Saya</button>
            <div class="wpm-pt-leaderboard" id="pt-my-predictions" hidden>
                <p class="wpm-pt-my-predictions__empty" id="pt-my-predictions-empty" hidden>Belum ada tebakan yang disimpan.</p>
                <div class="wpm-pt-match-list" id="pt-my-predictions-list"></div>
            </div>

            <!-- ---- Leaderboard (shared, visible under both tabs) ---- -->
            <button type="button" class="wpm-pt-leaderboard-toggle" id="pt-leaderboard-toggle" aria-expanded="false">🏆 Lihat Leaderboard</button>
            <div class="wpm-pt-leaderboard" id="pt-leaderboard" hidden>
                <p class="wpm-pt-leaderboard__loading" id="pt-leaderboard-loading">Memuat leaderboard…</p>
                <ol class="wpm-pt-leaderboard__list" id="pt-leaderboard-list"></ol>
            </div>
        </div>
    </main>

    <script src="assets/games/js/prediksi-trivia.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
