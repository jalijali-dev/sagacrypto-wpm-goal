<?php
declare(strict_types=1);

/**
 * Juggling Bola (slug/files stay `keepie-uppie` — operator renamed the
 * DISPLAY title only, mid-build; see games/index.php's card title) —
 * Games Hub's sixth game (8 Sep 2026, brief "Games Hub #6: Keepie-Uppie
 * (Juggling)"). Same standalone-page approach as the
 * other 5 games (see games/air-hockey/index.php's docblock) — no
 * site-header.php/site-footer.php, own <head>, own CSS/JS.
 *
 * 100% client-side, zero backend — same as air-hockey/penalty-kick/
 * quiz-bola/slot-bola (game #5, Prediksi & Trivia, is the ONE exception
 * that needs a database, see its own docblock/docs/DECISIONS.md for
 * why — this game is back to the normal pattern, not another
 * exception). High score lives in localStorage only, never sent
 * anywhere.
 *
 * Vanilla JS + Canvas 2D, no engine/library, no dependency on any other
 * game file. `TEAMS` (nickname/color picker data) is a COPY of
 * penalty-kick.js's array, not a shared import — see
 * assets/games/js/keepie-uppie.js's docblock for the reasoning (this
 * was an explicit either/or choice in the brief; duplication was
 * picked as the safer option over editing the already-shipped,
 * already-tested penalty-kick.js).
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Juggling Bola — Sagagoal Games';
$pageDescription = 'Juggling bola tanpa henti — tap dengan timing pas biar bola nggak jatuh. Makin lama makin cepat, kejar rekor tertinggimu!';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/keepie-uppie.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/keepie-uppie.js') ?: 1;

// Browser tab favicon (same pattern as the other 5 games/* pages — see
// games/index.php's comment for why this deliberately does NOT use
// wpm_image() on a nested page).
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
         the other 5 games/* pages). -->
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
    <link rel="stylesheet" href="assets/games/css/keepie-uppie.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-ku">
    <div class="wpm-ku-topbar">
        <a class="wpm-games-back wpm-ku-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-ku-topbar__title">Juggling Bola</span>
        <div class="wpm-ku-topbar__right">
            <!-- Same mute-button pattern as the other 5 games — audio
                 only ever starts inside a real click handler, never at
                 page load (see keepie-uppie.js's initAudio()). -->
            <button type="button" class="wpm-ku-mute-btn" id="ku-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-ku-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-ku-stage">
        <!-- Team select (purely cosmetic — jersey color only, same as
             penalty-kick.js's panel, never touches gravity/scoring). -->
        <div class="wpm-ku-panel" id="ku-panel-team">
            <h1 class="wpm-ku-panel__title">Pilih Timnas</h1>
            <p class="wpm-ku-panel__hint">Warna jersey pemain ikut negara yang kamu pilih.</p>
            <div class="wpm-ku-team-grid" id="ku-team-grid" role="group" aria-label="Pilih negara"></div>
        </div>

        <!-- Start panel (shown after a team is picked) -->
        <div class="wpm-ku-panel" id="ku-panel-start" hidden>
            <h1 class="wpm-ku-panel__title">Juggling Bola</h1>
            <p class="wpm-ku-panel__hint" id="ku-team-hint">Tap/klik dengan timing pas buat mantulin bola. Makin lama makin susah — jangan sampai bola jatuh!</p>
            <p class="wpm-ku-panel__highscore" id="ku-start-highscore" hidden></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="ku-start-btn">Mulai Main</button>
            <button type="button" class="wpm-ku-change-team-btn" id="ku-change-team-btn">Ganti negara</button>
        </div>

        <!-- Live scoreboard + canvas -->
        <div class="wpm-ku-board" id="ku-board" hidden>
            <div class="wpm-ku-scoreboard">
                <div class="wpm-ku-scoreboard__side">
                    <span class="wpm-ku-scoreboard__label">Sentuhan</span>
                    <span class="wpm-ku-scoreboard__score" id="ku-touch-count">0</span>
                </div>
                <span class="wpm-ku-multiplier-badge" id="ku-multiplier-badge" hidden>x1</span>
                <div class="wpm-ku-scoreboard__side">
                    <span class="wpm-ku-scoreboard__label">Rekor</span>
                    <span class="wpm-ku-scoreboard__score" id="ku-highscore-live">0</span>
                </div>
            </div>
            <div class="wpm-ku-canvas-wrap">
                <canvas id="ku-canvas" aria-label="Area juggling"></canvas>
            </div>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--ghost" id="ku-restart-btn">Ganti Tim / Ulang</button>
        </div>

        <!-- End overlay -->
        <div class="wpm-ku-panel wpm-ku-panel--overlay" id="ku-panel-end" hidden>
            <h2 class="wpm-ku-panel__title" id="ku-end-title">Bola Jatuh!</h2>
            <p class="wpm-ku-panel__score" id="ku-end-score"></p>
            <p class="wpm-ku-panel__hint" id="ku-end-highscore"></p>
            <button type="button" class="wpm-ah-btn wpm-ah-btn--primary" id="ku-play-again-btn">Main Lagi</button>
        </div>
    </main>

    <script src="assets/games/js/keepie-uppie.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
