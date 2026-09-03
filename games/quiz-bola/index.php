<?php
declare(strict_types=1);

/**
 * Kuis Bola — Games Hub's third game (3 Sep 2026, brief "Games Hub —
 * game ketiga, Kuis Bola"). Same standalone-page approach as
 * games/air-hockey/index.php and games/penalty-kick/index.php (see the
 * former's docblock for the full reasoning) — no site-header.php/
 * site-footer.php, own <head>, own CSS/JS.
 *
 * Unlike the first two games, this one is DOM/CSS-driven, not Canvas —
 * a timed multiple-choice quiz has no continuous render loop to speak
 * of (see assets/games/js/quiz-bola.js's docblock), so there's no
 * <canvas> element here at all, just a question card + option buttons
 * + a timer bar, same panel-swap structure (start -> live -> end
 * overlay) as the other two games for product-family consistency.
 *
 * All game logic (including the entire hardcoded question bank) lives
 * in assets/games/js/quiz-bola.js — vanilla JS, no engine/library, no
 * dependency on air-hockey.js/penalty-kick.js (kept fully separate per
 * the brief, even though it reuses the same "synthesize a tone via
 * Web Audio" pattern by design, not by import — see docs/DECISIONS.md,
 * 3 Sep 2026 entry).
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Kuis Bola — Sagagoal Games';
$pageDescription = 'Kuis pilihan ganda seputar sepak bola, 10 soal dengan timer — semakin cepat jawab benar, semakin besar skornya. Pilih level Easy, Medium, atau Hard.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/quiz-bola.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/quiz-bola.js') ?: 1;

// Browser tab favicon (same pattern as games/index.php, games/air-hockey/
// index.php, games/penalty-kick/index.php — see the first of those for
// why this deliberately does NOT use wpm_image() on a nested page).
$wpmSiteSettings = wpm_site_settings($pdo);
$wpmFaviconRaw = trim((string) ($wpmSiteSettings['favicon_path'] ?? ''));
if ($wpmFaviconRaw === '') {
    $wpmFaviconRaw = trim((string) ($wpmSiteSettings['logo_path'] ?? ''));
}
$wpmFaviconUrl = $wpmFaviconRaw !== '' ? $wpmFaviconRaw : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <!-- NOT wpm_base_href()/wpm_site_url() — see games/index.php's <base>
         comment for why (that helper only works for flat root-level
         scripts; this file is two directories below the root, same as
         games/air-hockey/index.php and games/penalty-kick/index.php). -->
    <base href="../../">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <meta name="robots" content="noindex, follow">
    <?php if ($wpmFaviconUrl !== null) : ?>
        <link rel="icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/games/css/games-landing.css?v=<?= (int) $cssVer ?>">
    <link rel="stylesheet" href="assets/games/css/quiz-bola.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-qb">
    <div class="wpm-qb-topbar">
        <a class="wpm-games-back wpm-qb-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-qb-topbar__title">Kuis Bola</span>
        <div class="wpm-qb-topbar__right">
            <!-- Same mute-button pattern as air-hockey.js/penalty-kick.js —
                 audio only ever starts inside the "Mulai Main" click
                 handler, never at page load (see quiz-bola.js's initAudio()). -->
            <button type="button" class="wpm-qb-mute-btn" id="qb-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-qb-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-qb-stage">
        <!-- Difficulty picker + start -->
        <div class="wpm-qb-panel" id="qb-panel-start">
            <img class="wpm-qb-panel__logo" src="assets/games/img/quiz-bola.png" alt="" width="96" height="96" loading="lazy">
            <h1 class="wpm-qb-panel__title">Kuis Bola</h1>
            <p class="wpm-qb-panel__hint">10 soal seputar sepak bola, tiap soal ada batas waktu — makin cepat jawab benar, makin besar skornya!</p>
            <div class="wpm-qb-difficulty" role="group" aria-label="Pilih tingkat kesulitan (durasi timer)">
                <button type="button" class="wpm-qb-difficulty__btn" data-difficulty="easy">Easy</button>
                <button type="button" class="wpm-qb-difficulty__btn is-selected" data-difficulty="medium">Medium</button>
                <button type="button" class="wpm-qb-difficulty__btn" data-difficulty="hard">Hard</button>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="qb-start-btn">Mulai Main</button>
        </div>

        <!-- Live quiz board -->
        <div class="wpm-qb-board" id="qb-board" hidden>
            <div class="wpm-qb-scoreboard">
                <div class="wpm-qb-scoreboard__side">
                    <span class="wpm-qb-scoreboard__label">Soal</span>
                    <span class="wpm-qb-scoreboard__score" id="qb-question-count">1/10</span>
                </div>
                <span class="wpm-qb-scoreboard__vs" id="qb-difficulty-badge">MEDIUM</span>
                <div class="wpm-qb-scoreboard__side">
                    <span class="wpm-qb-scoreboard__label">Skor</span>
                    <span class="wpm-qb-scoreboard__score" id="qb-score">0</span>
                </div>
            </div>

            <div class="wpm-qb-timer-track" aria-hidden="true">
                <div class="wpm-qb-timer-fill" id="qb-timer-fill"></div>
            </div>

            <div class="wpm-qb-card">
                <p class="wpm-qb-card__question" id="qb-question-text"></p>
                <div class="wpm-qb-options" id="qb-options" role="group" aria-label="Pilihan jawaban"></div>
            </div>
        </div>

        <!-- End-of-quiz overlay -->
        <div class="wpm-qb-panel wpm-qb-panel--overlay" id="qb-panel-end" hidden>
            <h2 class="wpm-qb-panel__title" id="qb-end-title">Selesai!</h2>
            <p class="wpm-qb-panel__score" id="qb-end-score"></p>
            <p class="wpm-qb-panel__hint" id="qb-end-breakdown"></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="qb-play-again-btn">Main Lagi</button>
        </div>
    </main>

    <script src="assets/games/js/quiz-bola.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
