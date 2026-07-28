<?php
declare(strict_types=1);

if (!defined('WPM_BOOTSTRAPPED')) {
    header('Location: ../index.php', true, 302);
    exit;
}

/**
 * Shared <head> + top nav for every public page. Caller sets these
 * variables before requiring this file:
 *   $pageTitle       (required)
 *   $pageDescription (required)
 *   $activeNav       (optional) — id from wpm_nav_menu() to highlight
 *   $canonicalUrl    (optional)
 *   $ogImage         (optional)
 *   $pageNoindex     (optional) — true emits <meta name="robots" content="noindex, nofollow">
 *   $extraHead       (optional) — raw HTML injected before </head>
 * Opens <main> at the end — the caller closes it and includes
 * includes/site-footer.php.
 */

$activeNav = $activeNav ?? '';
$currentYear = date('Y');
$wpmMenu = wpm_nav_menu($pdo);

// Site branding — falls back to the original hardcoded defaults whenever
// the admin hasn't filled in Site Settings yet (or a field is left blank),
// so the site never shows an empty name/tagline.
$wpmSiteSettings  = wpm_site_settings($pdo);
$wpmSiteName      = trim((string) ($wpmSiteSettings['site_name'] ?? '')) !== ''
    ? (string) $wpmSiteSettings['site_name'] : 'Sagagoal';
$wpmSiteTagline   = trim((string) ($wpmSiteSettings['site_tagline'] ?? '')) !== ''
    ? (string) $wpmSiteSettings['site_tagline'] : 'Livescore & Berita Bola';
$wpmSiteLogoUrl   = wpm_image((string) ($wpmSiteSettings['logo_path'] ?? ''));
// Dedicated favicon first; fall back to the main site logo so the tab
// icon still shows the brand even if a separate favicon was never
// uploaded in Site Settings.
$wpmFaviconUrl    = wpm_image((string) ($wpmSiteSettings['favicon_path'] ?? '')) ?? $wpmSiteLogoUrl;

$cssPath = __DIR__ . '/../assets/css/site.css';
$jsPath  = __DIR__ . '/../assets/js/site.js';
$cssVer  = @filemtime($cssPath) ?: 1;
$jsVer   = @filemtime($jsPath) ?: 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>
      // Set the theme attribute before first paint — no flash/snap between
      // themes on load. Order: localStorage preference, then OS preference,
      // then dark (this site's original default look).
      (function () {
        var saved = null;
        try { saved = localStorage.getItem('sagagoal_theme'); } catch (e) {}
        var theme = saved === 'light' || saved === 'dark'
          ? saved
          : (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>
    <meta charset="UTF-8">
    <!-- Must come first: makes every relative asset/link on this page
         resolve against the site root, not the current URL's own depth
         (needed since clean URLs like /artikel/<slug> sit one segment
         "deeper" than the old /artikel.php ever did). -->
    <base href="<?= wpm_esc(wpm_base_href()) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <?php if (!empty($wpmFaviconUrl)) : ?>
        <link rel="icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= wpm_esc($wpmFaviconUrl) ?>">
    <?php endif; ?>
    <?php if (!empty($canonicalUrl)) : ?>
        <link rel="canonical" href="<?= wpm_esc($canonicalUrl) ?>">
    <?php endif; ?>
    <?php if (!empty($pageNoindex)) : ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <meta property="og:title" content="<?= wpm_esc($pageTitle) ?>">
    <meta property="og:description" content="<?= wpm_esc($pageDescription) ?>">
    <meta property="og:type" content="website">
    <?php if (!empty($ogImage)) : ?>
        <meta property="og:image" content="<?= wpm_esc($ogImage) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/site.css?v=<?= (int) $cssVer ?>">
    <?= $extraHead ?? '' ?>

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HZ988K60RH"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-HZ988K60RH');
    </script>

    <!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PSSVTMGS');</script>
<!-- End Google Tag Manager -->
</head>
<body class="crypto-theme">
<div class="crypto-bg" aria-hidden="true"></div>

<header class="crypto-nav">
    <div class="crypto-nav__inner">
        <a href="index.php" class="crypto-logo">
            <?php if ($wpmSiteLogoUrl !== null && $wpmSiteLogoUrl !== '') : ?>
                <img class="crypto-logo__mark crypto-logo__mark--img" src="<?= wpm_esc($wpmSiteLogoUrl) ?>" alt="<?= wpm_esc($wpmSiteName) ?> logo">
            <?php else : ?>
                <span class="crypto-logo__mark" aria-hidden="true">SG</span>
            <?php endif; ?>
            <span>
                <span class="crypto-logo__text"><?= wpm_esc($wpmSiteName) ?></span>
                <span class="crypto-logo__tag"><?= wpm_esc($wpmSiteTagline) ?></span>
            </span>
        </a>

        <nav aria-label="Menu utama">
            <ul class="crypto-nav__menu">
                <?php foreach ($wpmMenu as $item) : ?>
                    <li><a href="<?= wpm_esc($item['href']) ?>" class="<?= $activeNav === $item['id'] ? 'is-active' : '' ?>"><?= wpm_esc($item['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="crypto-nav__actions">
            <a class="crypto-nav__search-btn" href="<?= wpm_esc(wpm_url_pencarian()) ?>" aria-label="Cari"><?= wpm_icon('search') ?></a>
            <label class="theme-toggle" for="theme-toggle-input">
                <span class="theme-toggle__icon"><?= wpm_icon('moon') ?></span>
                <span class="theme-toggle__label u-hide-mobile">Mode Gelap</span>
                <span class="theme-toggle__switch">
                    <input type="checkbox" id="theme-toggle-input" class="theme-toggle__input" aria-label="Mode Gelap">
                    <span class="theme-toggle__track"><span class="theme-toggle__thumb"></span></span>
                </span>
            </label>
            <button type="button" class="crypto-nav__toggle" id="crypto-nav-toggle" aria-label="Buka menu">
                <span></span>
            </button>
        </div>
    </div>
</header>

<?php
$wpmBreakingNewsHtml = wpm_breaking_news_markup($pdo);
?>
<?php if ($wpmBreakingNewsHtml !== '') : ?>
<div class="site-ticker-row">
    <div class="site-ticker-row__inner">
        <?= $wpmBreakingNewsHtml ?>
    </div>
</div>
<?php endif; ?>

<?= wpm_render_ad_slot($pdo, 'header') ?>
<?= wpm_render_ad_slot($pdo, 'below-main-menu') ?>

<main>
