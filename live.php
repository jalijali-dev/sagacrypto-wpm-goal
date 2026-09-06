<?php
declare(strict_types=1);

/**
 * Sagagoal — Live Streaming (6 Sep 2026, brief "Live Streaming"). Public
 * page at /live (a flat root script like index.php/artikel.php — matched
 * by .htaccess's generic extension-less alias rule, no dedicated rewrite
 * needed since the filename already matches wpm_url_live()'s 'live' slug).
 *
 * Reads the singleton live_streaming_settings row (managed from
 * cms-admin/pages/live-streaming.php) via wpm_live_streaming_settings() —
 * see that function's docblock in includes/site-bootstrap.php for the
 * full read-side story, and wpm_nav_menu() for how is_live drives the
 * "🔴 Live" nav badge shown across every other page on the site.
 *
 * Provider-agnostic embed (revised same day as first draft — operator:
 * "biar bisa dinamis copy paste embed apapun... stream pake apa aja"):
 * whatever the operator pasted into embed_code (a full <iframe> snippet
 * from YouTube/Cloudflare Stream/Facebook Live/Twitch/anything, or a bare
 * URL) gets turned into a player via wpm_render_live_embed() — see that
 * function for exactly how. This page has zero provider-specific logic
 * of its own on purpose.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$wpmLive = wpm_live_streaming_settings($pdo);
$wpmIsLive = (int) ($wpmLive['is_live'] ?? 0) === 1;
$wpmStreamTitle = trim((string) ($wpmLive['stream_title'] ?? ''));
$wpmStreamDescription = trim((string) ($wpmLive['stream_description'] ?? ''));
$wpmEmbedHtml = $wpmIsLive ? wpm_render_live_embed((string) ($wpmLive['embed_code'] ?? '')) : null;

// Only actually show the player if BOTH is_live is on AND embed_code
// resolved to something renderable — same guard rail as the admin form
// (which blocks saving is_live on with an empty embed_code), kept here
// too in case the DB row is ever edited directly or the pasted value
// doesn't match either recognized shape (see wpm_render_live_embed()).
$wpmShowPlayer = $wpmEmbedHtml !== null;

$pageTitle = $wpmIsLive
    ? ('🔴 Live: ' . ($wpmStreamTitle !== '' ? $wpmStreamTitle : 'Siaran Langsung') . ' — Sagagoal')
    : 'Live Streaming — Sagagoal';
$pageDescription = $wpmStreamDescription !== ''
    ? $wpmStreamDescription
    : ($wpmIsLive ? 'Nonton siaran langsung sepak bola di Sagagoal, gratis langsung dari browser.' : 'Belum ada siaran langsung saat ini — pantau terus, siaran berikutnya akan tampil otomatis di sini.');
$activeNav = 'live';
$canonicalUrl = wpm_site_url(wpm_url_live());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> Live Streaming</nav>
        <span class="section-kicker"><?= $wpmIsLive ? '🔴 SEDANG LIVE' : 'LIVE STREAMING' ?></span>
        <h1><?= wpm_esc($wpmIsLive && $wpmStreamTitle !== '' ? $wpmStreamTitle : 'Live Streaming') ?></h1>
        <?php if ($wpmStreamDescription !== '') : ?>
            <div class="page-hero-lead"><?= wpm_esc($wpmStreamDescription) ?></div>
        <?php endif; ?>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <?php if ($wpmShowPlayer) : ?>
            <div class="glass-card wpm-live-player-card">
                <div class="wpm-live-player-card__frame">
                    <?= $wpmEmbedHtml ?>
                </div>
            </div>
        <?php else : ?>
            <div class="glass-card empty-state" style="padding:48px 24px;">
                <?= wpm_icon('info') ?>
                <p>Belum ada siaran langsung saat ini. Pantau terus halaman ini — begitu ada pertandingan live, siarannya akan otomatis tampil di sini.</p>
                <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url('')) ?>" style="margin-top:16px;display:inline-flex;">Kembali ke Beranda</a>
            </div>
        <?php endif; ?>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
