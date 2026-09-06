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
$wpmFooterSports = wpm_sports_modules_by_placement($pdo, 'footer');
$wpmFooterPages = wpm_special_pages_for_footer($pdo);
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
                <a href="<?= wpm_esc(wpm_site_url('')) ?>" class="crypto-logo footer-brand__logo">
                    <?php if ($wpmSiteLogoUrl !== null && $wpmSiteLogoUrl !== '') : ?>
                        <?php if (($wpmSiteLogoUrlDark ?? null) !== null && $wpmSiteLogoUrlDark !== $wpmSiteLogoUrl) : ?>
                            <img class="crypto-logo__mark crypto-logo__mark--img footer-brand__logo-mark wpm-logo--light" src="<?= wpm_esc($wpmSiteLogoUrl) ?>" alt="<?= wpm_esc($wpmSiteName) ?> logo">
                            <img class="crypto-logo__mark crypto-logo__mark--img footer-brand__logo-mark wpm-logo--dark" src="<?= wpm_esc($wpmSiteLogoUrlDark) ?>" alt="<?= wpm_esc($wpmSiteName) ?> logo">
                        <?php else : ?>
                            <img class="crypto-logo__mark crypto-logo__mark--img footer-brand__logo-mark" src="<?= wpm_esc($wpmSiteLogoUrl) ?>" alt="<?= wpm_esc($wpmSiteName) ?> logo">
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="crypto-logo__mark footer-brand__logo-mark" aria-hidden="true">SG</span>
                    <?php endif; ?>
                    <span class="crypto-logo__text" style="font-size:24px;"><?= wpm_esc($wpmSiteName) ?></span>
                </a>
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
                    <?php foreach ($wpmFooterSports as $module) : ?>
                        <li><a href="<?= wpm_esc((string) $module['route_slug']) ?>"><?= wpm_esc((string) $module['label']) ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($wpmFooterPages as $specialPage) : ?>
                        <li><a href="<?= wpm_esc((string) $specialPage['slug']) ?>"><?= wpm_esc((string) $specialPage['title']) ?></a></li>
                    <?php endforeach; ?>
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
            <?php
            // Same is-live class as site-header.php's desktop <ul> — see
            // wpm_nav_menu()/wpm_live_streaming_settings() in
            // includes/site-bootstrap.php for where `is_live` comes from.
            $navLinkClasses = trim((($activeNav ?? '') === $item['id'] ? 'is-active' : '') . (!empty($item['is_live']) ? ' is-live' : ''));
            ?>
            <a href="<?= wpm_esc($item['href']) ?>" class="<?= wpm_esc($navLinkClasses) ?>"><?= wpm_esc($item['label']) ?></a>
            <?php if ($item['id'] === 'berita') : ?>
                <!-- "Games" (6 Sep 2026) — mobile drawer ONLY, deliberately NOT
                     added to wpm_nav_menu() itself: that array also feeds the
                     desktop <ul class="crypto-nav__menu"> in site-header.php,
                     and the operator confirmed this entry is mobile-only.
                     Hardcoded here rather than looped from $wpmMenu/DB since
                     it's not a Special Page / Sports Module — just a static
                     link to the standalone games/ hub. Placed right after
                     "Berita" (Opsi A, operator-confirmed 6 Sep 2026) so the
                     core content nav (Beranda/sport modules/Berita) stays
                     together above it, ahead of the static Tentang
                     Kami/Kontak special pages that follow in the loop.
                     Distinct accent styling (.crypto-nav__mobile-games) so it
                     reads as a fun/feature entry, not another plain nav link. -->
                <a href="<?= wpm_esc(wpm_site_url('games/')) ?>" class="crypto-nav__mobile-games">
                    <span class="crypto-nav__mobile-games__icon">🎮</span> Games
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php
// Floating bottom nav (mobile only), 27 Agu 2026 — ADDITIONAL to the top
// header (includes/site-header.php), not a replacement: that header
// keeps its logo/search/theme-toggle/hamburger exactly as-is. This is
// purely a thumb-reachable quick-nav for small screens (display:none
// above 767px, see assets/css/site.css). See wpm_bottom_nav_items()
// (includes/site-bootstrap.php) for why it's a capped 4-icon subset of
// $wpmMenu rather than all 6 items, and why it never grows past that
// even if more sport modules get activated later.
$wpmBottomNavItems = wpm_bottom_nav_items($wpmMenu);
?>
<nav class="wpm-bottom-nav" aria-label="Navigasi cepat">
    <?php foreach ($wpmBottomNavItems as $item) : ?>
        <a href="<?= wpm_esc($item['href']) ?>" class="wpm-bottom-nav__item<?= ($activeNav ?? '') === $item['id'] ? ' is-active' : '' ?><?= !empty($item['is_live']) ? ' wpm-bottom-nav__item--live' : '' ?>">
            <span class="wpm-bottom-nav__icon">
                <?= wpm_icon($item['icon']) ?>
                <?php if (!empty($item['is_live'])) : ?>
                    <!-- "notif online" (6 Sep 2026, operator request) — pulsing
                         red dot on the icon itself, reuses the same
                         wpmLivePulse keyframe as the desktop/drawer nav badge
                         (see assets/css/site.css's .crypto-nav__menu a.is-live). -->
                    <span class="wpm-bottom-nav__live-dot" aria-hidden="true"></span>
                <?php endif; ?>
            </span>
            <span class="wpm-bottom-nav__label"><?= wpm_esc($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <!-- Reuses the SAME #crypto-nav-mobile drawer as the header's hamburger
         button (#crypto-nav-toggle) — see assets/js/site.js's openMobile(),
         wired to this button's id too, not a second drawer/duplicate logic. -->
    <button type="button" class="wpm-bottom-nav__item wpm-bottom-nav__item--menu" id="wpm-bottom-nav-menu" aria-label="Buka menu lengkap">
        <span class="wpm-bottom-nav__icon"><?= wpm_icon('menu') ?></span>
        <span class="wpm-bottom-nav__label">Menu</span>
    </button>
</nav>

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

<?= wpm_floating_contact_buttons($wpmSiteSettings) ?>

<?php
// Push Notification (Firebase Cloud Messaging), 27 Agu 2026 — only wire
// up the "Aktifkan Notifikasi" button when the admin has actually turned
// the feature on AND filled both pieces of config the browser side
// needs (VAPID public key, Firebase web app config — see cms-admin/
// includes/PushNotificationHelper.php's docblock for why there are two
// separate config blobs). Any one missing = render nothing at all,
// rather than a button that's guaranteed to fail when clicked.
$wpmPushEnabled = (int) ($wpmSiteSettings['push_notification_enabled'] ?? 0) === 1;
$wpmPushVapidKey = trim((string) ($wpmSiteSettings['fcm_vapid_public_key'] ?? ''));
$wpmPushWebConfigRaw = trim((string) ($wpmSiteSettings['fcm_web_app_config_json'] ?? ''));
$wpmPushWebConfig = $wpmPushWebConfigRaw !== '' ? json_decode($wpmPushWebConfigRaw, true) : null;
$wpmPushReady = $wpmPushEnabled && $wpmPushVapidKey !== '' && is_array($wpmPushWebConfig);
?>
<?php if ($wpmPushReady) : ?>
<!-- Redesigned 28 Agu 2026 (was a floating bottom pill button — kept
     colliding/stacking with other floating mobile elements: sticky ad,
     WhatsApp/Telegram, bottom nav). Now a centered modal with
     persuasive copy, shown once per eligible visit rather than a
     persistent floating button. -->
<div id="wpm-push-modal" class="wpm-push-modal" hidden>
    <div class="wpm-push-modal__backdrop" id="wpm-push-modal-backdrop"></div>
    <div class="wpm-push-modal__card" role="dialog" aria-modal="true" aria-labelledby="wpm-push-modal-title">
        <div class="wpm-push-modal__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="28" height="28"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </div>
        <h3 id="wpm-push-modal-title" class="wpm-push-modal__title">Jangan Ketinggalan Berita Bola!</h3>
        <p class="wpm-push-modal__body">Aktifkan notifikasi biar kamu tau duluan tiap ada hasil pertandingan, gosip transfer, sampai skandal terbaru — langsung masuk ke HP kamu, 100% <strong>GRATIS</strong>. Bisa dimatiin kapan aja.</p>
        <div class="wpm-push-modal__actions">
            <button type="button" id="wpm-push-modal-allow" class="wpm-push-modal__btn wpm-push-modal__btn--primary">Aktifkan Notifikasi</button>
            <button type="button" id="wpm-push-modal-later" class="wpm-push-modal__btn wpm-push-modal__btn--secondary">Nanti Saja</button>
        </div>
    </div>
</div>
<style>
  .wpm-push-modal {
    position: fixed; inset: 0; z-index: 300;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  .wpm-push-modal[hidden] { display: none; }
  .wpm-push-modal__backdrop {
    /* Light backdrop, same idea as .wpm-popup-ad's own overlay elsewhere
       on this site — just enough to separate the card from the page
       behind it, not a heavy full-screen block-out. */
    position: absolute; inset: 0;
    background: rgba(10, 6, 24, 0.4);
  }
  .wpm-push-modal__card {
    position: relative;
    max-width: 320px; width: 100%;
    background: #17102b;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    padding: 22px 20px 18px;
    text-align: center;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.4);
    animation: wpm-push-modal-in 0.22s ease-out;
  }
  @keyframes wpm-push-modal-in {
    from { opacity: 0; transform: translateY(12px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  .wpm-push-modal__icon {
    width: 46px; height: 46px; margin: 0 auto 12px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);
    color: #0a0618;
  }
  .wpm-push-modal__icon svg { width: 22px; height: 22px; }
  .wpm-push-modal__title {
    font-size: 16px; font-weight: 700; color: #fff;
    margin: 0 0 8px; line-height: 1.3;
  }
  .wpm-push-modal__body {
    font-size: 13px; line-height: 1.55; color: rgba(255, 255, 255, 0.75);
    margin: 0 0 18px;
  }
  .wpm-push-modal__body strong { color: #fb923c; }
  .wpm-push-modal__actions { display: flex; flex-direction: column; gap: 10px; }
  .wpm-push-modal__btn {
    width: 100%; padding: 12px 16px; border-radius: 999px; border: none;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: transform 0.15s ease, opacity 0.15s ease;
  }
  .wpm-push-modal__btn:active { transform: scale(0.98); }
  .wpm-push-modal__btn--primary {
    background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);
    color: #0a0618;
    box-shadow: 0 6px 18px rgba(251, 146, 60, 0.35);
  }
  .wpm-push-modal__btn--secondary {
    background: transparent; color: rgba(255, 255, 255, 0.55);
  }
  .wpm-push-modal__btn:disabled { opacity: 0.6; cursor: default; }
</style>
<script>
(function () {
  var modal = document.getElementById('wpm-push-modal');
  var allowBtn = document.getElementById('wpm-push-modal-allow');
  var laterBtn = document.getElementById('wpm-push-modal-later');
  var backdrop = document.getElementById('wpm-push-modal-backdrop');
  if (!modal || !allowBtn || !('Notification' in window) || !('serviceWorker' in navigator)) { return; }

  var VAPID_KEY = <?= json_encode($wpmPushVapidKey) ?>;
  var WEB_CONFIG = <?= json_encode($wpmPushWebConfig) ?>;
  var SUBSCRIBE_URL = <?= json_encode(wpm_esc(wpm_site_url('api/push-subscribe.php'))) ?>;

  // "Nanti Saja" dismissal — redesigned 28 Agu 2026 from a persistent
  // floating button into a modal popup. A modal that pops up on EVERY
  // page view for a visitor who already said "not now" would get
  // annoying fast (unlike the old quiet floating button), so dismissing
  // suppresses it for a few days rather than the whole rest of the
  // session/forever. Notification.permission is still the real source
  // of truth for "has this been resolved" (see the 27 Aug 2026 fix
  // below it) — this localStorage key ONLY throttles re-showing the
  // popup itself, it never blocks the actual subscribe flow.
  var DISMISS_KEY = 'wpm_push_modal_dismissed_at';
  // Changed 28 Agu 2026 (was 3 days, briefly tried 5 minutes) — operator
  // wants visitors who haven't subscribed yet to keep getting
  // re-prompted more often than the original 3-day snooze, but 5
  // minutes turned out too aggressive: real risk of annoying visitors
  // into permanently blocking notifications (a 'denied' permission
  // can't be reset by JS, unlike 'default'), and Chrome can silently
  // downgrade an origin's permission prompts to a "quiet" UI site-wide
  // if it detects spammy repeated requests. Operator explicitly requested
  // shortening from 6h -> 1h (28 Agu 2026) for more frequent re-prompting.
  // Still fully silenced the moment permission is actually 'granted' or
  // 'denied' (see the check below), so this only affects visitors who
  // keep saying "not now".
  var DISMISS_SNOOZE_MS = 1 * 60 * 60 * 1000; // 1 hour
  function recentlyDismissed() {
    try {
      var at = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10) || 0;
      return (Date.now() - at) < DISMISS_SNOOZE_MS;
    } catch (e) { return false; }
  }
  function markDismissed() {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
  }

  // Fixed 27 Aug 2026, still applies to the modal: Notification.permission
  // itself is the single source of truth for "can we (re-)offer this
  // right now" — 'default' means never granted/denied, OR reset back to
  // that state after a browser-level unsubscribe. A denied permission
  // can't be re-requested by JS anyway, so there's nothing to offer then.
  function maybeShowModal() {
    if (Notification.permission === 'default' && modal.hidden && !recentlyDismissed()) {
      modal.hidden = false;
    }
  }

  if (Notification.permission === 'default' && !recentlyDismissed()) {
    // Small delay so the popup doesn't fight the page's own initial
    // render/paint, and doesn't feel like it's ambushing the visitor
    // before they've even seen the page.
    setTimeout(maybeShowModal, 1500);
  }

  // Re-check periodically (28 Agu 2026) — a visitor who dismisses and
  // then just keeps reading the SAME page (no reload/navigation) would
  // otherwise never see this again until their next page load, even
  // after the 5-minute snooze above expires. This makes the snooze
  // actually take effect while staying on one page too, not just across
  // navigations. Harmless once permission is granted/denied — the
  // Notification.permission check inside maybeShowModal() makes this a
  // no-op forever after that point.
  setInterval(maybeShowModal, 5 * 60 * 1000);

  function closeModal() { modal.hidden = true; }
  laterBtn.addEventListener('click', function () { markDismissed(); closeModal(); });
  backdrop.addEventListener('click', function () { markDismissed(); closeModal(); });

  var firebaseLoadPromise = null;
  function loadFirebaseSdk() {
    if (firebaseLoadPromise) { return firebaseLoadPromise; }
    firebaseLoadPromise = new Promise(function (resolve, reject) {
      var s1 = document.createElement('script');
      s1.src = 'https://www.gstatic.com/firebasejs/10.13.0/firebase-app-compat.js';
      s1.onload = function () {
        var s2 = document.createElement('script');
        s2.src = 'https://www.gstatic.com/firebasejs/10.13.0/firebase-messaging-compat.js';
        s2.onload = resolve;
        s2.onerror = reject;
        document.head.appendChild(s2);
      };
      s1.onerror = reject;
      document.head.appendChild(s1);
    });
    return firebaseLoadPromise;
  }

  // Shared registration flow — used both by the explicit opt-in click AND
  // by the silent background refresh below. Resolves the FCM token and
  // POSTs it to SUBSCRIBE_URL, which is an upsert (re-registering an
  // already-known token is a harmless no-op server-side, and — this is
  // the point — flips it back to is_active = 1 if the server had
  // previously deactivated it after a failed send).
  function registerToken() {
    return navigator.serviceWorker.ready
      .then(function (registration) {
        return loadFirebaseSdk().then(function () { return registration; });
      })
      .then(function (registration) {
        if (!firebase.apps || !firebase.apps.length) {
          firebase.initializeApp(WEB_CONFIG);
        }
        var messaging = firebase.messaging();
        // Foreground handler — onBackgroundMessage (sw.js) only fires
        // while no tab has focus; a visitor actively reading the site
        // when a push arrives needs this instead, same data-only shape.
        // Re-registering this on every call is harmless (Firebase just
        // replaces the listener), so it's fine to run from both the
        // click flow and the silent refresh flow below.
        messaging.onMessage(function (payload) {
          var data = (payload && payload.data) || {};
          if (Notification.permission === 'granted') {
            registration.showNotification(data.title || 'Sagagoal', {
              body: data.body || '',
              icon: 'assets/img/icon-192.png',
              image: data.image || undefined,
              data: { url: data.url || './' },
            });
          }
        });
        return messaging.getToken({ vapidKey: VAPID_KEY, serviceWorkerRegistration: registration });
      })
      .then(function (token) {
        if (!token) { throw new Error('No token returned.'); }
        return fetch(SUBSCRIBE_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'fcm_token=' + encodeURIComponent(token),
        });
      });
  }

  allowBtn.addEventListener('click', function () {
    allowBtn.disabled = true;
    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') {
        closeModal(); // denied or dismissed — stop offering this session
        return;
      }
      return registerToken().then(function () {
        closeModal();
      });
    }).catch(function () {
      // Permission API rejected, Firebase CDN unreachable, getToken()
      // failed, subscribe request failed — whatever it was, just close
      // the popup so the visitor isn't stuck staring at it; the modal
      // reappears on a later visit (subject to the dismiss snooze) since
      // Notification.permission is still 'default' if it never granted.
      allowBtn.disabled = false;
      closeModal();
    });
  });

  // Silent background refresh (27 Aug 2026) — fixes a gap where a token
  // that Firebase invalidates (device idle, app data cleared, token
  // rotated, etc.) never gets replaced automatically: permission stays
  // 'granted' so the opt-in button never reappears to trigger a new
  // getToken() call, yet the server-side row is already marked inactive
  // after one failed send. Previously the only fix was to manually
  // revoke the browser's notification permission and re-grant it. Now,
  // once per ~20h per browser (throttled via localStorage so this
  // doesn't fire a Firebase SDK load + token fetch on every single page
  // view), silently re-run the same registration flow whenever
  // permission is already 'granted' — this both keeps a still-valid
  // token's last_seen_at fresh and, if the token had quietly gone stale,
  // gets a new one and re-activates the subscription server-side.
  if (Notification.permission === 'granted') {
    var REFRESH_KEY = 'wpm_push_last_refresh';
    var REFRESH_INTERVAL_MS = 20 * 60 * 60 * 1000; // ~20 hours
    var lastRefresh = 0;
    try { lastRefresh = parseInt(localStorage.getItem(REFRESH_KEY) || '0', 10) || 0; } catch (e) {}

    if (Date.now() - lastRefresh > REFRESH_INTERVAL_MS) {
      registerToken()
        .then(function () {
          try { localStorage.setItem(REFRESH_KEY, String(Date.now())); } catch (e) {}
        })
        .catch(function () {
          // Best-effort only — never surface this to the visitor, and
          // don't update the throttle timestamp so the next page view
          // tries again instead of waiting out the full interval.
        });
    }
  }
})();
</script>
<?php endif; ?>

<script src="assets/js/site.js?v=<?= (int) $jsVer ?>" defer></script>
<script>
  // PWA service worker registration (added 20 Aug 2026) — deliberately
  // here (end of body, after load) rather than in <head>, so it never
  // competes with the page's own render/paint for the main thread.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= wpm_esc(wpm_site_url('sw.js')) ?>');
    });
  }
</script>
</body>
</html>
