<?php
declare(strict_types=1);

/**
 * Public per-player history page (12 Sep 2026, operator request) — "lihat
 * history tebakan lengkap user X". Looked up by `?code=<public_code>`
 * (see includes/GamesShared.php's `game_players.public_code` schema
 * comment for why it's a random code, not the raw player id, and not the
 * nickname — nicknames aren't unique). Data comes entirely from
 * api/game-user-history.php, fetched client-side — this file itself does
 * NOT touch the database; it's a thin static shell around that endpoint,
 * same "PHP page + separate JSON API" split already used by
 * games/prediksi-trivia/index.php + api/game-fixtures-today.php.
 *
 * Deliberately its OWN file (not a mode of index.php via a query param)
 * — index.php's job is "the game itself" (nickname gate, tabs, live
 * fixture list); this page's job is "look at someone else's public
 * result", a fundamentally different, much simpler read-only view with
 * no nickname gate of its own. Same standalone-page pattern as every
 * other games/* page (own <head>, own <base>, no site-header.php).
 *
 * FAIRNESS NOTE — see api/game-user-history.php's own docblock: this
 * page can only ever show ALREADY-SCORED matches for the looked-up
 * player, never an in-progress/unscored prediction, specifically because
 * this page is public.
 */

require_once __DIR__ . '/../../includes/site-bootstrap.php';

$pageTitle = 'Riwayat Tebakan — Sagagoal Games';
$pageDescription = 'Lihat riwayat tebakan skor pertandingan dan total poin pemain Prediksi & Trivia di Sagagoal.';
$cssVer = @filemtime(__DIR__ . '/../../assets/games/css/prediksi-trivia.css') ?: 1;
$jsVer = @filemtime(__DIR__ . '/../../assets/games/js/prediksi-trivia-user.js') ?: 1;

// Browser tab favicon — same pattern as every other games/* page, see
// games/index.php's comment for why this deliberately does NOT use
// wpm_image() on a nested page.
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
         the other games/* pages). -->
    <base href="../../">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <!-- noindex — this is one specific player's page, not content that
         benefits from being individually indexed; the game itself
         (games/prediksi-trivia/) stays index,follow. -->
    <meta name="robots" content="noindex, follow">
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
        <a class="wpm-games-back wpm-pt-topbar__link" href="games/prediksi-trivia/">&larr; Prediksi &amp; Trivia</a>
        <span class="wpm-pt-topbar__title">Riwayat Tebakan</span>
        <div class="wpm-pt-topbar__right">
            <a class="wpm-games-back wpm-pt-topbar__link" href="./">Sagagoal &rarr;</a>
        </div>
    </div>

    <main class="wpm-pt-stage">
        <div class="wpm-pt-main" id="ptu-main">
            <p class="wpm-pt-whoami" id="ptu-loading">Memuat…</p>

            <!-- Filled in by prediksi-trivia-user.js once the fetch to
                 api/game-user-history.php resolves. Starts empty/hidden
                 rather than SSR'd — this page has no server-side DB
                 access of its own by design (see file docblock). -->
            <div id="ptu-notfound" class="wpm-pt-panel" hidden>
                <h1 class="wpm-pt-panel__title">Pemain tidak ditemukan</h1>
                <p class="wpm-pt-panel__hint">Link ini mungkin salah ketik, atau pemainnya belum pernah menebak pertandingan apapun.</p>
            </div>

            <div id="ptu-profile" hidden>
                <div class="wpm-pt-panel" id="ptu-summary">
                    <h1 class="wpm-pt-panel__title" id="ptu-nickname"></h1>
                    <p class="wpm-pt-panel__hint">Total poin: <strong id="ptu-total-points"></strong></p>
                </div>

                <p class="wpm-pt-hint">Riwayat tebakan yang sudah selesai dinilai (pertandingan yang belum kickoff sengaja tidak ditampilkan di halaman publik ini).</p>
                <div class="wpm-pt-match-list" id="ptu-history-list"></div>
                <p class="wpm-pt-empty" id="ptu-history-empty" hidden>Belum ada riwayat tebakan yang selesai dinilai.</p>
            </div>
        </div>
    </main>

    <script src="assets/games/js/prediksi-trivia-user.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
