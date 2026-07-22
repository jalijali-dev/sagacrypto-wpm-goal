<?php
declare(strict_types=1);

/**
 * Sagagoal — Tentang Kami page. Moved out of index.php when the homepage
 * became a tabbed news feed; content/logic unchanged from the old
 * homepage "Tentang Kami" section. Managed from
 * cms-admin/pages/about-settings.php via the `landing_sections` table
 * (page_key='about'). Keep these defaults in sync with
 * about-settings.php's ABOUT_DEFAULTS and index.php's old fallback.
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
try {
    $aboutStmt = $pdo->prepare("SELECT section_key, title, subtitle FROM landing_sections WHERE page_key = 'about' AND status = 'published'");
    $aboutStmt->execute();
    $featureKeys = ['feature_1', 'feature_2', 'feature_3', 'feature_4'];
    foreach ($aboutStmt->fetchAll() as $row) {
        $key = (string) ($row['section_key'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $subtitle = trim((string) ($row['subtitle'] ?? ''));
        if ($key === 'main') {
            if ($title !== '') {
                $aboutTitle = $title;
            }
            if ($subtitle !== '') {
                $aboutBody = $subtitle;
            }
        } else {
            $idx = array_search($key, $featureKeys, true);
            if ($idx !== false) {
                if ($title !== '') {
                    $aboutFeatures[$idx]['title'] = $title;
                }
                if ($subtitle !== '') {
                    $aboutFeatures[$idx]['desc'] = $subtitle;
                }
            }
        }
    }
} catch (Throwable $e) {
    // Keep static fallback.
}

$pageTitle = 'Tentang Kami — Sagagoal';
$pageDescription = $aboutBody;
$activeNav = 'tentang';
$canonicalUrl = wpm_site_url('tentang.php');

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Tentang Kami</nav>
        <span class="section-kicker">Tentang Kami</span>
        <h1><?= wpm_esc($aboutTitle) ?></h1>
        <p><?= wpm_esc($aboutBody) ?></p>
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

<?= wpm_app_promo_section() ?>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
