<?php
declare(strict_types=1);

/**
 * Air Hockey — the one playable game in the MVP (30 Agu 2026, brief
 * "Games Hub — MVP"). Same standalone-page approach as games/index.php
 * (see that file's docblock) — no site-header.php/site-footer.php, own
 * <head>, own CSS/JS, nothing shared with the main site's theme.
 *
 * All game logic lives in assets/games/js/air-hockey.js (vanilla JS +
 * Canvas 2D, no engine/library — see docs/DECISIONS.md, 30 Agu 2026
 * entry, for why). This file only renders the page shell: canvas,
 * difficulty picker, score readout, and the two orientation links
 * ("back to hub" / "back to Sagagoal") the brief requires so a player
 * never feels like they've left the site entirely.
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Air Hockey — Sagagoal Games';
$pageDescription = 'Main Air Hockey bertema sepak bola langsung di browser. Lawan komputer, pilih level Easy, Medium, atau Hard.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/air-hockey.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/air-hockey.js') ?: 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <!-- NOT wpm_base_href()/wpm_site_url() — see games/index.php's <base>
         comment for why (that helper only works for flat root-level
         scripts; this file is two directories below the root). -->
    <base href="../../">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <meta name="robots" content="noindex, follow">
    <link rel="stylesheet" href="assets/games/css/games-landing.css?v=<?= (int) $cssVer ?>">
    <link rel="stylesheet" href="assets/games/css/air-hockey.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-ah">
    <div class="wpm-ah-topbar">
        <a class="wpm-games-back wpm-ah-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-ah-topbar__title">Air Hockey</span>
        <div class="wpm-ah-topbar__right">
            <!-- Sound toggle (30 Agu 2026 revamp) — defaults ON but audio
                 itself never actually plays a note until the player clicks
                 "Mulai Main" (see air-hockey.js's initAudio(), called from
                 that same click handler) — browsers block audio before a
                 user gesture anyway, and this keeps that gesture tied to
                 something the player already meant to do. -->
            <button type="button" class="wpm-ah-mute-btn" id="ah-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-ah-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-ah-stage">
        <!-- Difficulty picker + start (shown before a match) -->
        <div class="wpm-ah-panel" id="ah-panel-start">
            <h1 class="wpm-ah-panel__title">Air Hockey</h1>
            <p class="wpm-ah-panel__hint">Gerakin kaki (mallet) buat mantulin bola ke gawang lawan. First to 7 menang.</p>
            <div class="wpm-ah-difficulty" role="group" aria-label="Pilih tingkat kesulitan">
                <button type="button" class="wpm-ah-difficulty__btn" data-difficulty="easy">Easy</button>
                <button type="button" class="wpm-ah-difficulty__btn is-selected" data-difficulty="medium">Medium</button>
                <button type="button" class="wpm-ah-difficulty__btn" data-difficulty="hard">Hard</button>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="ah-start-btn">Mulai Main</button>
        </div>

        <!-- Live scoreboard + canvas (shown during a match) -->
        <div class="wpm-ah-board" id="ah-board" hidden>
            <div class="wpm-ah-scoreboard">
                <div class="wpm-ah-scoreboard__side">
                    <span class="wpm-ah-scoreboard__label">Komputer</span>
                    <span class="wpm-ah-scoreboard__score" id="ah-score-cpu">0</span>
                </div>
                <span class="wpm-ah-scoreboard__vs" id="ah-difficulty-badge">MEDIUM</span>
                <div class="wpm-ah-scoreboard__side">
                    <span class="wpm-ah-scoreboard__label">Kamu</span>
                    <span class="wpm-ah-scoreboard__score" id="ah-score-player">0</span>
                </div>
            </div>
            <div class="wpm-ah-canvas-wrap">
                <canvas id="ah-canvas" aria-label="Papan air hockey"></canvas>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--ghost" id="ah-restart-btn">Ganti Level / Ulang</button>
        </div>

        <!-- Match end overlay -->
        <div class="wpm-ah-panel wpm-ah-panel--overlay" id="ah-panel-end" hidden>
            <h2 class="wpm-ah-panel__title" id="ah-end-title">Menang!</h2>
            <p class="wpm-ah-panel__hint" id="ah-end-score"></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="ah-play-again-btn">Main Lagi</button>
        </div>
    </main>

    <script src="assets/games/js/air-hockey.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
