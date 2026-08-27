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
<button type="button" id="wpm-push-optin" class="wpm-push-optin" aria-label="Aktifkan notifikasi artikel baru" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="18" height="18"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <span>Aktifkan Notifikasi</span>
</button>
<style>
  .wpm-push-optin {
    position: fixed; left: 16px; bottom: 16px; z-index: 40;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 999px; border: none;
    background: #fb923c; color: #0a0618; font-size: 13px; font-weight: 600;
    box-shadow: 0 6px 18px rgba(0,0,0,.25); cursor: pointer;
  }
  .wpm-push-optin[hidden] { display: none; }
  @media (min-width: 768px) { .wpm-push-optin { left: 24px; bottom: 24px; } }
</style>
<script>
(function () {
  var btn = document.getElementById('wpm-push-optin');
  if (!btn || !('Notification' in window) || !('serviceWorker' in navigator)) { return; }

  var VAPID_KEY = <?= json_encode($wpmPushVapidKey) ?>;
  var WEB_CONFIG = <?= json_encode($wpmPushWebConfig) ?>;
  var SUBSCRIBE_URL = <?= json_encode(wpm_esc(wpm_site_url('api/push-subscribe.php'))) ?>;

  // Fixed 27 Aug 2026: this used to also gate on a localStorage flag
  // ('wpm_push_subscribed') set the first time someone subscribed. That
  // flag never got cleared, so a visitor who later hit Chrome's own
  // per-notification "Unsubscribe" action (which resets the browser's
  // Notification.permission back to 'default') would never see the
  // opt-in button reappear — the stale flag kept hiding it forever.
  // Notification.permission itself is the single source of truth for
  // "can we (re-)offer this right now": 'default' means never
  // granted/denied, OR reset back to that state after an unsubscribe —
  // either way, offering the button again is exactly correct. A denied
  // permission can't be re-requested by JS anyway (browser policy), so
  // there's nothing useful to offer in that state.
  if (Notification.permission === 'default') {
    btn.hidden = false;
  }

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

  btn.addEventListener('click', function () {
    btn.disabled = true;
    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') {
        btn.hidden = true; // denied or dismissed — stop offering this session
        return;
      }
      return registerToken().then(function () {
        btn.hidden = true;
      });
    }).catch(function () {
      // Permission API rejected, Firebase CDN unreachable, getToken()
      // failed, subscribe request failed — whatever it was, just leave
      // the button visible/re-enabled so the visitor can try again,
      // never let this throw further or break the rest of the page.
      btn.disabled = false;
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
