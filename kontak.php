<?php
declare(strict_types=1);

/**
 * Sagagoal — Kontak page. Moved out of index.php when the homepage became
 * a tabbed news feed; content/logic unchanged from the old homepage
 * "Kontak" section (same contact-submit.php target, same Site Settings
 * fields).
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$wpmContactStatus = (string) ($_GET['contact'] ?? '');

$pageTitle = 'Kontak — Sagagoal';
$pageDescription = 'Kerja sama, kirim rilis berita, atau gabung komunitas pembaca Sagagoal.';
$activeNav = 'kontak';
$canonicalUrl = wpm_site_url('kontak.php');

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Kontak</nav>
        <span class="section-kicker">Kontak</span>
        <h1>Kerja Sama &amp; Gabung Komunitas</h1>
        <p>Tertarik kerja sama, kirim rilis berita, atau gabung komunitas pembaca Sagagoal? Kirim pesan lewat form di bawah.</p>
    </div>
</section>

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
                <?php if ($wpmContactStatus === 'success') : ?>
                    <div class="form-alert form-alert--success">Pesan kamu berhasil dikirim. Tim Sagagoal akan menghubungi balik secepatnya.</div>
                <?php elseif ($wpmContactStatus === 'error') : ?>
                    <div class="form-alert form-alert--error">Pesan gagal dikirim. Mohon periksa kembali form dan coba lagi.</div>
                <?php endif; ?>

                <form method="post" action="contact-submit.php" novalidate>
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
                    <div class="form-row">
                        <label class="form-label" for="wpm-subject">Subjek</label>
                        <input class="form-input" type="text" id="wpm-subject" name="subject" placeholder="Kerja sama, rilis berita, dll." maxlength="160">
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

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
