<?php
declare(strict_types=1);

/**
 * Sagagoal — Tentang Kami page. Hero (title+content) now migrated into
 * Special Pages (page_key='about', see includes/SpecialPages.php) — Fase
 * 3 v2, 24 Jul 2026. The 4 feature cards below stay fully hardcoded (not
 * in special_pages.content): fixed at 4, rarely changes, not meant to be
 * admin-editable. Managed from cms-admin/pages/special-pages.php now,
 * not the old about-settings.php/landing_sections (that admin page +
 * table are retired by this same migration).
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$aboutTitle = 'Sagagoal, Portal Livescore & Berita Bola Terpercaya';
$aboutBody = 'Sagagoal menghadirkan jadwal pertandingan, live score, klasemen liga, dan berita sepak bola dalam satu tempat — disajikan ringkas, akurat, dan mudah dipahami untuk pembaca dari berbagai level.';
$aboutFeatures = [
    ['icon' => 'megaphone', 'title' => 'Berita Bola', 'desc' => 'Update berita sepak bola tercepat dan terpercaya, dari transfer pemain hingga hasil pertandingan.'],
    ['icon' => 'chart', 'title' => 'Live Score', 'desc' => 'Skor pertandingan real-time, dari kick-off sampai peluit akhir, langsung di halaman utama.'],
    ['icon' => 'book', 'title' => 'Jadwal Pertandingan', 'desc' => 'Jadwal lengkap pertandingan dari liga-liga pilihan, mudah dipantau setiap hari.'],
    ['icon' => 'flame', 'title' => 'Klasemen Liga', 'desc' => 'Klasemen liga terkini, update otomatis setiap pertandingan selesai.'],
];

$aboutPage = wpm_special_page_by_slug($pdo, 'tentang-kami');
if ($aboutPage !== null) {
    if (trim((string) $aboutPage['title']) !== '') {
        $aboutTitle = (string) $aboutPage['title'];
    }
    if (trim((string) $aboutPage['content']) !== '') {
        $aboutBody = (string) $aboutPage['content'];
    }
}

$pageTitle = 'Tentang Kami — Sagagoal';
$pageDescription = $aboutBody;
$activeNav = 'special-about';
$canonicalUrl = wpm_site_url(wpm_url_tentang());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> Tentang Kami</nav>
        <span class="section-kicker">Tentang Kami</span>
        <h1><?= wpm_esc($aboutTitle) ?></h1>
        <div class="page-hero-lead"><?= $aboutBody ?></div>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <div class="crypto-grid crypto-grid--4">
            <?php foreach ($aboutFeatures as $feature) : ?>
                <div class="glass-card crypto-card">
                    <div class="crypto-card__icon"><?= wpm_icon($feature['icon']) ?></div>
                    <h3><?= wpm_esc($feature['title']) ?></h3>
                    <p><?= wpm_esc($feature['desc']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= wpm_app_promo_section($pdo) ?>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
