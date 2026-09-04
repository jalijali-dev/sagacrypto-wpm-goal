<?php
declare(strict_types=1);

/**
 * Slot Bola — Games Hub's fourth game (3 Sep 2026, brief "Games Hub —
 * game keempat, Slot Bola"). Same standalone-page approach as the
 * other 3 games (see games/air-hockey/index.php's docblock) — no
 * site-header.php/site-footer.php, own <head>, own CSS/JS.
 *
 * IMPORTANT — read before touching this game at all: this is a
 * football-themed 3x3 slot machine for PURE ENTERTAINMENT, decided
 * explicitly by the operator to have NO money/credit/coin system in
 * any form — spins are free and unlimited, forever, same as every
 * other game in this hub not rate-limiting play. See this file's own
 * "Batasan" note further down, slot-bola.js's docblock, and
 * docs/DECISIONS.md (3 Sep 2026 entry) for the full reasoning. Do NOT
 * add a credit/currency/top-up/deposit mechanic to this game without
 * an explicit new operator decision — this was asked and answered
 * directly, it is not an oversight.
 *
 * DOM/CSS-driven, not Canvas (same reasoning as games/quiz-bola/
 * index.php — a slot machine has no continuous physics/collision loop
 * either). All game logic lives in assets/games/js/slot-bola.js.
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Slot Bola — Sagagoal Games';
$pageDescription = 'Mesin slot 3x3 bertema sepak bola. Putar gulungan, cocokkan simbol, kumpulkan poin — spin gratis tanpa batas, murni hiburan.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/slot-bola.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/slot-bola.js') ?: 1;

// Browser tab favicon (same pattern as the other 3 games/* pages — see
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
         the other 3 games/* pages). -->
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
    <link rel="stylesheet" href="assets/games/css/slot-bola.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games wpm-sb">
    <div class="wpm-sb-topbar">
        <a class="wpm-games-back wpm-sb-topbar__link" href="games/">&larr; Games</a>
        <span class="wpm-sb-topbar__title">Slot Bola</span>
        <div class="wpm-sb-topbar__right">
            <!-- Same mute-button pattern as the other 3 games — audio
                 only ever starts inside the "Spin" click handler, never
                 at page load (see slot-bola.js's initAudio()). -->
            <button type="button" class="wpm-sb-mute-btn" id="sb-mute-btn" aria-pressed="false" aria-label="Matikan suara">🔊</button>
            <a class="wpm-games-back wpm-sb-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-sb-stage">
        <p class="wpm-sb-hint">Putar gulungan, cocokkan 3 simbol sebaris — spin gratis, tanpa batas, murni buat seru-seruan (bukan judi, tidak ada kredit/koin sama sekali).</p>

        <div class="wpm-sb-scoreboard">
            <span class="wpm-sb-scoreboard__label">Skor</span>
            <span class="wpm-sb-scoreboard__score" id="sb-score">0</span>
        </div>

        <div class="wpm-sb-machine">
            <div class="wpm-sb-reels" id="sb-reels">
                <div class="wpm-sb-reel" data-reel="0"><div class="wpm-sb-reel__strip" id="sb-strip-0"></div></div>
                <div class="wpm-sb-reel" data-reel="1"><div class="wpm-sb-reel__strip" id="sb-strip-1"></div></div>
                <div class="wpm-sb-reel" data-reel="2"><div class="wpm-sb-reel__strip" id="sb-strip-2"></div></div>
                <!-- 3 payline markers (top/mid/bottom row), highlighted by
                     slot-bola.js on a win — purely visual, sits above the
                     reels via z-index, doesn't affect reel layout. -->
                <div class="wpm-sb-payline" id="sb-payline-0" data-row="0"></div>
                <div class="wpm-sb-payline" id="sb-payline-1" data-row="1"></div>
                <div class="wpm-sb-payline" id="sb-payline-2" data-row="2"></div>
            </div>
        </div>

        <p class="wpm-sb-result" id="sb-result" aria-live="polite">&nbsp;</p>

        <button type="button" class="wpm-ah-btn wpm-ah-btn--primary wpm-sb-spin-btn" id="sb-spin-btn">Spin!</button>

        <button type="button" class="wpm-sb-paytable-toggle" id="sb-paytable-toggle" aria-expanded="false">Lihat tabel poin</button>
        <div class="wpm-sb-paytable" id="sb-paytable" hidden></div>
    </main>

    <script src="assets/games/js/slot-bola.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
