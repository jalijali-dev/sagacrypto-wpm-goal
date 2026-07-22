<?php
declare(strict_types=1);

if (!defined('WPM_BOOTSTRAPPED')) {
    header('Location: ../index.php', true, 302);
    exit;
}

/**
 * Shared footer + closing tags for every public page. Expects the caller
 * to have already closed </main>. Renders the Footer ad slot, popup ad,
 * and sticky-bottom-mobile ad (all optional — silently absent if inactive
 * or unconfigured), then the footer markup, mobile nav drawer, and JS.
 */

$wpmMenu = wpm_nav_menu($pdo);
$adSettings = wpm_ad_settings($pdo);
$popupAd = (empty($adSettings) || (int) ($adSettings['ads_enabled'] ?? 1) === 1) ? wpm_ad_pick($pdo, 'popup') : null;
$stickyAd = (empty($adSettings) || ((int) ($adSettings['ads_enabled'] ?? 1) === 1 && (int) ($adSettings['sticky_mobile_enabled'] ?? 1) === 1))
    ? wpm_ad_pick($pdo, 'sticky-bottom-mobile', 'global', null, 'mobile')
    : null;
?>
<?= wpm_render_ad_slot($pdo, 'footer') ?>

<footer class="crypto-footer">
    <div class="crypto-container">
        <div class="footer-grid">
            <div class="footer-brand">
                <span class="crypto-logo__text" style="font-size:24px;">Sagagoal</span>
                <p>Portal livescore dan berita sepak bola — jadwal pertandingan, skor live, klasemen liga, disajikan ringkas dan mudah dipahami.</p>
            </div>
            <div>
                <p class="footer-heading">Menu</p>
                <ul class="footer-links">
                    <?php foreach ($wpmMenu as $item) : ?>
                        <li><a href="<?= wpm_esc($item['href']) ?>"><?= wpm_esc($item['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <p class="footer-heading">Konten</p>
                <ul class="footer-links">
                    <li><a href="<?= wpm_esc(wpm_url_kategori()) ?>">Semua Berita</a></li>
                    <li><a href="<?= wpm_esc(wpm_url_olahraga()) ?>">Sport</a></li>
                    <li><a href="<?= wpm_esc(wpm_url_tentang()) ?>">Tentang Kami</a></li>
                </ul>
            </div>
        </div>

        <p class="footer-disclaimer">Data live score & jadwal pertandingan pada situs ini bersumber dari API pihak ketiga dan disajikan sebagaimana adanya.</p>

        <div class="footer-bottom">
            <span>&copy; <?= wpm_esc($currentYear) ?> Sagagoal. Seluruh hak cipta dilindungi.</span>
            <span>Portal livescore &amp; berita sepak bola.</span>
        </div>
    </div>
</footer>

<div class="crypto-nav__mobile" id="crypto-nav-mobile">
    <div class="crypto-nav__mobile-panel">
        <button type="button" class="crypto-nav__mobile-close" id="crypto-nav-mobile-close" aria-label="Tutup menu">&times;</button>
        <?php foreach ($wpmMenu as $item) : ?>
            <a href="<?= wpm_esc($item['href']) ?>" class="<?= ($activeNav ?? '') === $item['id'] ? 'is-active' : '' ?>"><?= wpm_esc($item['label']) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($popupAd !== null) : ?>
    <?php
    try {
        $pdo->prepare('UPDATE advertisements SET impressions = impressions + 1 WHERE id = :id')->execute(['id' => (int) $popupAd['id']]);
    } catch (Throwable $e) {
        // Best-effort.
    }
    ?>
    <div class="wpm-popup-ad" id="wpm-popup-ad">
        <div class="wpm-popup-ad__panel">
            <button type="button" class="wpm-popup-ad__close" id="wpm-popup-ad-close" aria-label="Tutup iklan">&times;</button>
            <?= wpm_ad_markup($popupAd, empty($adSettings) || (int) ($adSettings['show_ad_label'] ?? 1) === 1, 'popup') ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($stickyAd !== null) : ?>
    <?php
    try {
        $pdo->prepare('UPDATE advertisements SET impressions = impressions + 1 WHERE id = :id')->execute(['id' => (int) $stickyAd['id']]);
    } catch (Throwable $e) {
        // Best-effort.
    }
    ?>
    <div class="wpm-sticky-ad" id="wpm-sticky-ad">
        <button type="button" class="wpm-sticky-ad__close" id="wpm-sticky-ad-close" aria-label="Tutup">&times;</button>
        <?= wpm_ad_markup($stickyAd, empty($adSettings) || (int) ($adSettings['show_ad_label'] ?? 1) === 1, 'sticky-bottom-mobile') ?>
    </div>
<?php endif; ?>

<script src="assets/js/site.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
