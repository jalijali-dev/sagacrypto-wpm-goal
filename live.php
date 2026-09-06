<?php
declare(strict_types=1);

/**
 * Sagagoal — Live Streaming (6 Sep 2026, brief "Live Streaming — Cloudflare
 * Stream"). Public page at /live (see includes/.htaccess-level routing —
 * this is a flat root script like index.php/artikel.php, no rewrite rule
 * needed since the filename already matches wpm_url_live()'s 'live' slug
 * via wpm_site_url(wpm_url_live())).
 *
 * Reads the singleton live_streaming_settings row (managed from
 * cms-admin/pages/live-streaming.php) via wpm_live_streaming_settings() —
 * see that function's docblock in includes/site-bootstrap.php for the
 * full read-side story, and wpm_nav_menu() for how is_live drives the
 * "🔴 Live" nav badge shown across every other page on the site.
 *
 * Embeds Cloudflare Stream's own iframe player
 * (https://iframe.cloudflarestream.com/<playback_id>) rather than
 * hand-rolling an HLS <video> + hls.js setup — Cloudflare's iframe already
 * handles adaptive bitrate, a poster frame, and a "stream hasn't started
 * yet" state on its own, so there's no custom JS to write/maintain here.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$wpmLive = wpm_live_streaming_settings($pdo);
$wpmIsLive = (int) ($wpmLive['is_live'] ?? 0) === 1;
$wpmPlaybackId = trim((string) ($wpmLive['playback_id'] ?? ''));
$wpmStreamTitle = trim((string) ($wpmLive['stream_title'] ?? ''));
$wpmStreamDescription = trim((string) ($wpmLive['stream_description'] ?? ''));

// Only actually embed the player if BOTH is_live is on AND a Playback ID
// is set — same guard rail as the admin form (which blocks saving is_live
// on with an empty Playback ID), kept here too in case the DB row is
// ever edited directly.
$wpmShowPlayer = $wpmIsLive && $wpmPlaybackId !== '';

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
                    <iframe
                        src="https://iframe.cloudflarestream.com/<?= wpm_esc($wpmPlaybackId) ?>?autoplay=true&muted=false"
                        loading="lazy"
                        style="border:none; position:absolute; top:0; left:0; height:100%; width:100%;"
                        allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;"
                        allowfullscreen="true"
                    ></iframe>
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
