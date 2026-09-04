<?php
declare(strict_types=1);

/**
 * Penalty Kick — Games Hub's second game (2 Sep 2026, brief "Games Hub —
 * game kedua, Penalty Kick"). Same standalone-page approach as
 * games/air-hockey/index.php (see that file's docblock for the full
 * reasoning — no site-header.php/site-footer.php, own <head>, own
 * CSS/JS). This file is a close structural mirror of
 * games/air-hockey/index.php (start panel -> live board -> end overlay,
 * same difficulty picker, same mute button placement) deliberately, so
 * the two games feel like the same product family — see
 * docs/DECISIONS.md (2 Sep 2026 entry) for why.
 *
 * All game logic lives in assets/games/js/penalty-kick.js (vanilla JS +
 * Canvas 2D, no engine/library, no dependency on air-hockey.js — kept
 * fully separate per the brief, even though the two files share the
 * same small "synthesize a tone" pattern by design, not by import).
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Penalty Kick — Sagagoal Games';
$pageDescription = 'Adu penalti lawan kiper komputer langsung di browser. Bidik sudut, jangan ketahuan arahnya. Pilih level Easy, Medium, atau Hard.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/penalty-kick.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/penalty-kick.js') ?: 1;

// Browser tab favicon (3 Sep 2026) — same fix as games/index.php and
// games/air-hockey/index.php, see the former's comment for why this is
// needed on every standalone games/* page, and why this deliberately
// does NOT use wpm_image() (wrong nested-directory base prefix).
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
         games/air-hockey/index.php — copied verbatim, see brief #1). -->
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
    <link rel="stylesheet" href="assets/games/css/penalty-kick.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-pk">
    <div class="wpm-pk-topbar">
        <a class="wpm-games-back wpm-pk-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-pk-topbar__title">Penalty Kick</span>
        <div class="wpm-pk-topbar__right">
            <!-- Same mute-button pattern as air-hockey.js — audio only
                 ever starts inside the "Mulai Main" click handler, never
                 at page load (see penalty-kick.js's initAudio()). -->
            <button type="button" class="wpm-pk-mute-btn" id="pk-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-pk-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-pk-stage">
        <!-- Team select (2 Sep 2026, operator request) — shown FIRST,
             before the difficulty picker. Purely cosmetic (which flag
             shows next to "Kamu" in the scoreboard) — does not touch
             gameplay/scoring/AI at all. Grid is rendered by JS from a
             data array (assets/games/js/penalty-kick.js's TEAMS), not
             hardcoded here, so the list is one place to maintain. -->
        <div class="wpm-pk-panel" id="pk-panel-team">
            <h1 class="wpm-pk-panel__title">Pilih Timnas</h1>
            <p class="wpm-pk-panel__hint">Main sebagai negara favoritmu.</p>
            <div class="wpm-pk-team-grid" id="pk-team-grid" role="group" aria-label="Pilih negara"></div>
        </div>

        <!-- Difficulty picker + start (shown after a team is picked) -->
        <div class="wpm-pk-panel" id="pk-panel-start" hidden>
            <h1 class="wpm-pk-panel__title">Penalty Kick</h1>
            <p class="wpm-pk-panel__hint" id="pk-team-hint">Klik/tap area gawang buat nembak. 5 tendangan — cetak gol sebanyak mungkin, jangan ketahuan kiper!</p>
            <div class="wpm-pk-difficulty" role="group" aria-label="Pilih tingkat kesulitan kiper">
                <button type="button" class="wpm-pk-difficulty__btn" data-difficulty="easy">Easy</button>
                <button type="button" class="wpm-pk-difficulty__btn is-selected" data-difficulty="medium">Medium</button>
                <button type="button" class="wpm-pk-difficulty__btn" data-difficulty="hard">Hard</button>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="pk-start-btn">Mulai Main</button>
            <button type="button" class="wpm-pk-change-team-btn" id="pk-change-team-btn">Ganti negara</button>
        </div>

        <!-- Live scoreboard + canvas (shown during a shootout) -->
        <div class="wpm-pk-board" id="pk-board" hidden>
            <div class="wpm-pk-scoreboard">
                <div class="wpm-pk-scoreboard__side">
                    <span class="wpm-pk-scoreboard__label">Tendangan</span>
                    <span class="wpm-pk-scoreboard__score" id="pk-shot-count">0/5</span>
                </div>
                <span class="wpm-pk-scoreboard__vs" id="pk-difficulty-badge">MEDIUM</span>
                <div class="wpm-pk-scoreboard__side">
                    <span class="wpm-pk-scoreboard__label"><span id="pk-team-flag-badge"></span> Gol</span>
                    <span class="wpm-pk-scoreboard__score" id="pk-goal-count">0</span>
                </div>
            </div>
            <div class="wpm-pk-canvas-wrap">
                <canvas id="pk-canvas" aria-label="Gawang penalti"></canvas>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--ghost" id="pk-restart-btn">Ganti Level / Ulang</button>
        </div>

        <!-- Shootout end overlay -->
        <div class="wpm-pk-panel wpm-pk-panel--overlay" id="pk-panel-end" hidden>
            <h2 class="wpm-pk-panel__title" id="pk-end-title">Selesai!</h2>
            <p class="wpm-pk-panel__hint" id="pk-end-score"></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="pk-play-again-btn">Main Lagi</button>
        </div>
    </main>

    <script src="assets/games/js/penalty-kick.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
