<?php
declare(strict_types=1);

/**
 * Sagagoal — generic Special Pages router. Reached via the clean URL
 * /<slug> (root .htaccess catch-all, placed after every other route rule),
 * which rewrites to this file as ?slug=<slug>.
 *
 * page_key === 'contact' gets the Kontak form template (migrated from the
 * old standalone kontak.php/contact-submit.php, now retired — same
 * `contact_messages` table, same admin inbox at
 * cms-admin/pages/contact-messages.php). Every other page_key gets the
 * generic title+content template.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';
require_once __DIR__ . '/includes/SpecialPages.php';

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug === '') {
    header('Location: index.php', true, 302);
    exit;
}

$page = wpm_special_page_by_slug($pdo, $slug);

if (!$page) {
    http_response_code(404);
    $pageTitle = 'Halaman Tidak Ditemukan — Sagagoal';
    $pageDescription = 'Halaman yang kamu cari tidak ditemukan atau belum diterbitkan.';
    require __DIR__ . '/includes/site-header.php';
    ?>
    <section class="crypto-section">
        <div class="crypto-container">
            <div class="empty-state">
                <?= wpm_icon('info') ?>
                <p>Halaman tidak ditemukan atau belum diterbitkan.</p>
                <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url('')) ?>" style="margin-top:16px;display:inline-flex;">Kembali ke Beranda</a>
            </div>
        </div>
    </section>
    </main>
    <?php require __DIR__ . '/includes/site-footer.php'; ?>
    <?php
    exit;
}

$pageKey = (string) $page['page_key'];
$isContact = $pageKey === 'contact';
$isAbout = $pageKey === 'about';
$contactStatus = '';

// Hardcoded feature cards for the About page (Fase 3 v2, 24 Jul 2026) —
// deliberately NOT stored in special_pages.content: layout is fixed at 4
// cards, rarely changes, and doesn't need to be admin-editable. Only the
// hero title/content above them come from the database. Keep this array
// in sync with tentang.php's copy (the actual route for /tentang-kami —
// this branch only fires if page.php is hit directly with
// ?slug=tentang-kami, since the dedicated .htaccess rule normally routes
// straight to tentang.php first).
$aboutFeatures = [
    ['icon' => 'megaphone', 'title' => 'Berita Bola', 'desc' => 'Update berita sepak bola tercepat dan terpercaya, dari transfer pemain hingga hasil pertandingan.'],
    ['icon' => 'chart', 'title' => 'Live Score', 'desc' => 'Skor pertandingan real-time, dari kick-off sampai peluit akhir, langsung di halaman utama.'],
    ['icon' => 'book', 'title' => 'Jadwal Pertandingan', 'desc' => 'Jadwal lengkap pertandingan dari liga-liga pilihan, mudah dipantau setiap hari.'],
    ['icon' => 'flame', 'title' => 'Klasemen Liga', 'desc' => 'Klasemen liga terkini, update otomatis setiap pertandingan selesai.'],
];

if ($isContact && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Honeypot: real visitors never fill this hidden field. Bots that do
    // get a fake "success" redirect so they don't retry, but nothing is stored.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: ' . $slug . '?contact=success', true, 302);
        exit;
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $subject  = trim((string) ($_POST['subject'] ?? ''));
    $message  = trim((string) ($_POST['message'] ?? ''));

    $isValid = $fullName !== ''
        && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && $message !== '';

    if (!$isValid) {
        header('Location: ' . $slug . '?contact=error', true, 302);
        exit;
    }

    try {
        $pdo->prepare(
            'INSERT INTO contact_messages (full_name, email, phone, subject, message, is_read, created_at)
             VALUES (:full_name, :email, :phone, :subject, :message, 0, NOW())'
        )->execute([
            'full_name' => mb_substr($fullName, 0, 120),
            'email'     => mb_substr($email, 0, 160),
            'phone'     => $whatsapp !== '' ? mb_substr($whatsapp, 0, 30) : null,
            'subject'   => $subject !== '' ? mb_substr($subject, 0, 160) : 'Pesan dari website',
            'message'   => mb_substr($message, 0, 4000),
        ]);
        header('Location: ' . $slug . '?contact=success', true, 302);
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $slug . '?contact=error', true, 302);
        exit;
    }
}

$contactStatus = (string) ($_GET['contact'] ?? '');

$pageTitle = trim((string) ($page['meta_title'] ?? '')) !== ''
    ? (string) $page['meta_title']
    : (string) $page['title'] . ' — Sagagoal';
$pageDescription = trim((string) ($page['meta_description'] ?? '')) !== ''
    ? (string) $page['meta_description']
    : wpm_excerpt((string) $page['content'], 160);
$activeNav = 'special-' . $pageKey;
$canonicalUrl = wpm_site_url($slug);

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> <?= wpm_esc((string) $page['title']) ?></nav>
        <span class="section-kicker"><?= wpm_esc((string) $page['title']) ?></span>
        <h1><?= wpm_esc((string) $page['title']) ?></h1>
        <?php if (($isAbout || $isContact) && trim((string) $page['content']) !== '') : ?>
            <div class="page-hero-lead"><?= (string) $page['content'] ?></div>
        <?php endif; ?>
    </div>
</section>

<?php if ($isContact) : ?>
<section class="crypto-section--tight">
    <div class="crypto-container">
        <?php
        $wpmContactEmail    = trim((string) ($wpmSiteSettings['email'] ?? ''));
        $wpmContactWa       = trim((string) ($wpmSiteSettings['whatsapp_number'] ?? ''));
        $wpmContactWaHref   = $wpmContactWa !== '' ? 'https://wa.me/' . preg_replace('/\D+/', '', $wpmContactWa) : '';
        $wpmContactCommunity = trim((string) ($wpmSiteSettings['instagram_url'] ?? ''));
        $wpmContactCommunityHref = $wpmContactCommunity !== ''
            ? (preg_match('#^https?://#i', $wpmContactCommunity) === 1
                ? $wpmContactCommunity
                : 'https://instagram.com/' . ltrim($wpmContactCommunity, '@/'))
            : '';
        ?>
        <div class="contact-grid">
            <div class="glass-card contact-info-card">
                <div class="contact-info-row">
                    <span class="contact-info-row__icon"><?= wpm_icon('mail') ?></span>
                    <span>
                        <span class="contact-info-row__label">Email</span><br>
                        <?php if ($wpmContactEmail !== '') : ?>
                            <a class="contact-info-row__value" href="mailto:<?= wpm_esc($wpmContactEmail) ?>"><?= wpm_esc($wpmContactEmail) ?></a>
                        <?php else : ?>
                            <span class="contact-info-row__value">Segera hadir</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="contact-info-row">
                    <span class="contact-info-row__icon"><?= wpm_icon('chat') ?></span>
                    <span>
                        <span class="contact-info-row__label">WhatsApp</span><br>
                        <?php if ($wpmContactWa !== '') : ?>
                            <a class="contact-info-row__value" href="<?= wpm_esc($wpmContactWaHref) ?>" target="_blank" rel="noopener"><?= wpm_esc($wpmContactWa) ?></a>
                        <?php else : ?>
                            <span class="contact-info-row__value">Segera hadir</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="contact-info-row">
                    <span class="contact-info-row__icon"><?= wpm_icon('pin') ?></span>
                    <span>
                        <span class="contact-info-row__label">Komunitas</span><br>
                        <?php if ($wpmContactCommunity !== '') : ?>
                            <a class="contact-info-row__value" href="<?= wpm_esc($wpmContactCommunityHref) ?>" target="_blank" rel="noopener"><?= wpm_esc($wpmContactCommunity) ?></a>
                        <?php else : ?>
                            <span class="contact-info-row__value">Segera hadir</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="glass-card contact-form-card">
                <?php if ($contactStatus === 'success') : ?>
                    <div class="form-alert form-alert--success">Pesan kamu berhasil dikirim. Tim Sagagoal akan menghubungi balik secepatnya.</div>
                <?php elseif ($contactStatus === 'error') : ?>
                    <div class="form-alert form-alert--error">Pesan gagal dikirim. Mohon periksa kembali form dan coba lagi.</div>
                <?php endif; ?>

                <form method="post" action="<?= wpm_esc($slug) ?>" novalidate>
                    <div class="form-row form-row--2col">
                        <div class="form-row">
                            <label class="form-label" for="wpm-name">Nama Lengkap</label>
                            <input class="form-input" type="text" id="wpm-name" name="full_name" placeholder="Nama kamu" required maxlength="120">
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="wpm-email">Email</label>
                            <input class="form-input" type="email" id="wpm-email" name="email" placeholder="nama@email.com" required maxlength="160">
                        </div>
                    </div>
                    <div class="form-row form-row--2col">
                        <div class="form-row">
                            <label class="form-label" for="wpm-whatsapp">WhatsApp</label>
                            <input class="form-input" type="text" id="wpm-whatsapp" name="whatsapp" placeholder="08xx-xxxx-xxxx" maxlength="30">
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="wpm-subject">Subjek</label>
                            <input class="form-input" type="text" id="wpm-subject" name="subject" placeholder="Kerja sama, rilis berita, dll." maxlength="160">
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="wpm-message">Pesan</label>
                        <textarea class="form-textarea" id="wpm-message" name="message" placeholder="Tulis pesan kamu di sini..." required maxlength="4000"></textarea>
                    </div>
                    <!-- Honeypot anti-spam field — stays empty for real users -->
                    <div class="hp-field" aria-hidden="true">
                        <label for="wpm-website">Website</label>
                        <input type="text" id="wpm-website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <button type="submit" class="crypto-btn crypto-btn--primary" style="width:100%;">Kirim Pesan</button>
                </form>
            </div>
        </div>
    </div>
</section>
<?php elseif ($isAbout) : ?>
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
<?php else : ?>
<section class="crypto-section--tight">
    <div class="crypto-container">
        <div class="glass-card special-page-content" style="padding:32px;">
            <?= (string) $page['content'] ?>
        </div>
    </div>
</section>
<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
