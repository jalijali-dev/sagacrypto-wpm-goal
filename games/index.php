<?php
declare(strict_types=1);

/**
 * Games Hub — landing page (MVP, 30 Agu 2026, brief "Games Hub — MVP").
 *
 * Deliberately does NOT include includes/site-header.php / site-footer.php
 * — those carry the main site's chrome (logo, top nav, theme toggle,
 * bottom nav, floating contact buttons, PWA service worker registration,
 * push-notification opt-in, etc.), none of which belongs on a page whose
 * whole point is to feel like a different, "gaming vibe" product living
 * inside Sagagoal, not a themed extension of the news site. Only
 * includes/site-bootstrap.php is loaded, for $pdo / wpm_esc() / wpm_icon()
 * / wpm_site_url() — this page owns its own <head> and markup entirely.
 *
 * Out of scope for this MVP (see docs/DECISIONS.md, 30 Agu 2026 entry):
 * sign-up form, server-side leaderboard/score storage, and every game
 * besides Air Hockey — those render below as "Segera Hadir" placeholder
 * cards so the hub's structure doesn't need reworking when they land.
 */

require_once __DIR__ . '/../includes/site-bootstrap.php';

$pageTitle = 'Sagagoal Games — Main Game Bola Gratis';
$pageDescription = 'Main mini-game bertema sepak bola langsung di browser, gratis, tanpa install. Air Hockey sudah bisa dimainkan — game lain segera hadir.';
$cssVer = @filemtime(__DIR__ . '/../assets/games/css/games-landing.css') ?: 1;

// Browser tab favicon (3 Sep 2026) — this page never included
// site-header.php (deliberately, see file-level comment above), so it
// never got the <link rel="icon"> tags every other page gets from
// there. Reuses the site-settings-driven favicon (falls back to the
// main site logo) instead of hardcoding a path, so it stays in sync if
// the operator ever changes it in cms-admin → Site Settings.
//
// NOT wpm_image() here — uploaded logo/favicon paths are stored as
// absolute web paths (e.g. "/uploads/site/favicon/x.png"), and
// wpm_image() prefixes those with wpm_base_path() (dirname of
// $_SERVER['SCRIPT_NAME']), which resolves to ".../games" on this
// nested page instead of the real site root — same class of bug as
// wpm_base_href()/wpm_site_url(), see the <base> comment above. An
// absolute "/..." path already ignores <base href> and resolves from
// the domain root on its own (per how browsers handle absolute-path
// URLs), so we use the raw stored path untouched — correct in
// production (root deploy) and in any local subfolder dev setup alike.
$wpmSiteSettings = wpm_site_settings($pdo);
$wpmFaviconRaw = trim((string) ($wpmSiteSettings['favicon_path'] ?? ''));
if ($wpmFaviconRaw === '') {
    $wpmFaviconRaw = trim((string) ($wpmSiteSettings['logo_path'] ?? ''));
}
$wpmFaviconUrl = $wpmFaviconRaw !== '' ? $wpmFaviconRaw : null;

/**
 * One card = one game. `href` null means it's a "Segera Hadir" placeholder
 * (not clickable) — the whole reason this is a list instead of hardcoded
 * markup twice is so the next game just becomes one more entry here.
 *
 * `accent` (30 Agu 2026 visual revamp) — one of the named variants
 * defined in games-landing.css (.wpm-game-card--orange/--cyan/--purple).
 * Per-card color is purely cosmetic (border/icon/status-pill tint), not
 * a new data concept — CrazyGames-style "each card its own color" per
 * the operator's landing-page reference, still Sagagoal/football themed
 * (icons stay football/trophy/flame, just recolored per card).
 *
 * `logo` (3 Sep 2026) — root-relative path to a per-game PNG logo
 * (operator-supplied artwork, background removed + recompressed, see
 * assets/games/img/) rendered instead of the generic `icon` glyph when
 * present. `icon` is kept as the fallback for any future card that
 * doesn't have custom artwork yet — `null` on `slot-bola` below (3 Sep
 * 2026, game #4) is exactly that case: no custom artwork commissioned
 * yet, falls back to the plain `wpm_icon('football')` glyph until the
 * operator supplies one as a separate follow-up.
 */
$wpmGames = [
    [
        'slug' => 'air-hockey',
        'title' => 'Air Hockey',
        'tagline' => 'Lawan komputer, 3 level kesulitan. Gerakin kaki, jaga gawangmu!',
        'icon' => 'football',
        'logo' => 'assets/games/img/air-hockey.png',
        // Root-relative (matches <base href="../"> below, which points
        // at the site root) — not wpm_site_url(), see the <base> comment
        // further down for why.
        'href' => 'games/air-hockey/',
        'status' => 'Main Sekarang',
        'accent' => 'orange',
    ],
    [
        'slug' => 'penalty-kick',
        'title' => 'Penalty Kick',
        'tagline' => 'Adu penalti lawan kiper. Bidik sudut, jangan ketahuan arahnya.',
        'icon' => 'trophy',
        'logo' => 'assets/games/img/penalty-kick.png',
        'href' => 'games/penalty-kick/',
        'status' => 'Main Sekarang',
        'accent' => 'cyan',
    ],
    [
        'slug' => 'quiz-bola',
        'title' => 'Kuis Bola',
        'tagline' => 'Seberapa jago kamu soal sepak bola? Buktikan lewat kuis cepat.',
        'icon' => 'flame',
        'logo' => 'assets/games/img/quiz-bola.png',
        'href' => 'games/quiz-bola/',
        'status' => 'Main Sekarang',
        'accent' => 'purple',
    ],
    [
        'slug' => 'slot-bola',
        'title' => 'Slot Bola',
        'tagline' => 'Putar gulungan, cocokkan simbol bola. Spin gratis, tanpa batas!',
        'icon' => 'football',
        // No custom PNG artwork yet (3 Sep 2026) — see the `logo` comment
        // above. Purely hiburan, no coins/credits — see slot-bola.js's
        // own docblock and docs/DECISIONS.md (3 Sep 2026 entry).
        'logo' => null,
        'href' => 'games/slot-bola/',
        'status' => 'Main Sekarang',
        'accent' => 'gold',
    ],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <!-- NOT wpm_base_href()/wpm_site_url() here — both derive the site
         root from dirname($_SERVER['SCRIPT_NAME']), which only works
         correctly for a flat root-level script (every existing public
         page — index.php, artikel.php, etc.). This file lives one
         directory below the root, so that helper resolves to
         ".../games" instead of the real root, breaking every asset/link
         on the page. A plain relative "../" — resolved by the browser
         against THIS document's own URL — works regardless of whether
         the app sits at the domain root (production) or a subfolder
         (local dev), with no dependency on that helper at all. -->
    <base href="../">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <meta name="robots" content="noindex, follow">
    <?php if ($wpmFaviconUrl !== null) : ?>
        <link rel="icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/games/css/games-landing.css?v=<?= (int) $cssVer ?>">
</head>
<body class="wpm-games">
    <a class="wpm-games-back" href="./">&larr; Kembali ke Sagagoal</a>

    <header class="wpm-games-hero">
        <p class="wpm-games-hero__kicker">SAGAGOAL GAMES</p>
        <h1 class="wpm-games-hero__title">Ambil Jeda. Main Bola.</h1>
        <p class="wpm-games-hero__sub">Mini-game bertema sepak bola, langsung di browser — gratis, tanpa install, tanpa akun.</p>
    </header>

    <main class="wpm-games-grid">
        <?php foreach ($wpmGames as $game) : ?>
            <?php $cardClass = 'wpm-game-card wpm-game-card--' . ($game['href'] !== null ? 'active' : 'soon') . ' wpm-game-card--' . wpm_esc($game['accent']); ?>
            <?php if ($game['href'] !== null) : ?>
                <a class="<?= $cardClass ?>" href="<?= wpm_esc($game['href']) ?>">
            <?php else : ?>
                <div class="<?= $cardClass ?>">
            <?php endif; ?>
                <span class="wpm-game-card__icon">
                    <?php if (!empty($game['logo'])) : ?>
                        <img src="<?= wpm_esc($game['logo']) ?>" alt="" loading="lazy" width="168" height="168">
                    <?php else : ?>
                        <?= wpm_icon($game['icon']) ?>
                    <?php endif; ?>
                </span>
                <h2 class="wpm-game-card__title"><?= wpm_esc($game['title']) ?></h2>
                <p class="wpm-game-card__tagline"><?= wpm_esc($game['tagline']) ?></p>
                <span class="wpm-game-card__status wpm-game-card__status--<?= $game['href'] !== null ? 'active' : 'soon' ?>">
                    <?= wpm_esc($game['status']) ?>
                </span>
            <?php if ($game['href'] !== null) : ?>
                </a>
            <?php else : ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </main>

    <footer class="wpm-games-footer">
        <img class="wpm-games-footer__logo" src="assets/img/sagagoal-logo-white.png" alt="Sagagoal" loading="lazy" width="140" height="64">
        <p>&copy; <?= wpm_esc(date('Y')) ?> <a href="./">sagagoal.com</a></p>
    </footer>
</body>
</html>
