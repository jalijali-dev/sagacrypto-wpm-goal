/* WPM Crypto Portal — front-end interactions (lightweight, no dependencies) */
(function () {
  var toggle = document.getElementById("crypto-nav-toggle");
  var mobile = document.getElementById("crypto-nav-mobile");
  var closeBtn = document.getElementById("crypto-nav-mobile-close");
  // Bottom nav's "Menu" button (includes/site-footer.php, 27 Agu 2026) —
  // opens this SAME drawer via this SAME openMobile(), not a second one.
  var bottomNavMenuBtn = document.getElementById("wpm-bottom-nav-menu");

  function openMobile() {
    if (mobile) { mobile.classList.add("is-open"); }
  }
  function closeMobile() {
    if (mobile) { mobile.classList.remove("is-open"); }
  }
  // Toggle (28 Agu 2026) — the bottom nav's "Menu" button used to only
  // ever call openMobile(), so clicking it again while the drawer was
  // already open did nothing (only the X button or backdrop/Escape could
  // close it). Now it flips open/closed like a normal toggle button.
  function toggleMobile() {
    if (!mobile) { return; }
    if (mobile.classList.contains("is-open")) { closeMobile(); } else { openMobile(); }
  }

  if (toggle) { toggle.addEventListener("click", toggleMobile); }
  if (closeBtn) { closeBtn.addEventListener("click", closeMobile); }
  if (bottomNavMenuBtn) { bottomNavMenuBtn.addEventListener("click", toggleMobile); }
  if (mobile) {
    mobile.addEventListener("click", function (e) {
      if (e.target === mobile) { closeMobile(); }
    });
    mobile.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", closeMobile);
    });
  }

  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { closeMobile(); }
  });

  /* Dark mode toggle — <head>'s inline script already set data-theme on
     <html> before paint (localStorage, falling back to prefers-color-scheme).
     This just syncs the checkbox to that state and persists future clicks. */
  var themeInput = document.getElementById("theme-toggle-input");
  if (themeInput) {
    themeInput.checked = document.documentElement.getAttribute("data-theme") === "dark";
    themeInput.addEventListener("change", function () {
      var theme = themeInput.checked ? "dark" : "light";
      document.documentElement.setAttribute("data-theme", theme);
      try { localStorage.setItem("sagagoal_theme", theme); } catch (e) { /* storage unavailable — theme just won't persist */ }
    });
  }

  /* Popup / sticky-bottom ad dismiss buttons */
  var popupAd = document.getElementById("wpm-popup-ad");
  var popupClose = document.getElementById("wpm-popup-ad-close");
  if (popupAd && popupClose) {
    popupClose.addEventListener("click", function () { popupAd.classList.add("is-hidden"); });
    popupAd.addEventListener("click", function (e) {
      if (e.target === popupAd) { popupAd.classList.add("is-hidden"); }
    });
  }
  var stickyAd = document.getElementById("wpm-sticky-ad");
  var stickyClose = document.getElementById("wpm-sticky-ad-close");
  if (stickyAd && stickyClose) {
    stickyClose.addEventListener("click", function () { stickyAd.classList.add("is-hidden"); });
  }

  /* Article page — "copy link" share button */
  var copyBtn = document.getElementById("wpm-copy-link");
  if (copyBtn) {
    copyBtn.addEventListener("click", function () {
      var url = window.location.href;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          copyBtn.setAttribute("title", "Link disalin!");
        });
      }
    });
  }

  /* Video ads (ad_type='video', autoplay enabled) — the server never adds
     the "autoplay" attribute (see wpm_ad_markup() in site-bootstrap.php),
     it only marks eligible <video> tags with data-autoplay="1". Playback
     is started/stopped here, driven purely by viewport visibility, so an
     autoplaying ad never plays while scrolled off-screen. */
  var autoplayVideos = Array.prototype.slice.call(document.querySelectorAll("video[data-autoplay='1']"));
  if (autoplayVideos.length && "IntersectionObserver" in window) {
    var videoObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.play().catch(function () { /* autoplay blocked — ignore */ });
          } else {
            entry.target.pause();
          }
        });
      },
      { threshold: 0.5 }
    );
    autoplayVideos.forEach(function (v) { videoObserver.observe(v); });
  }
}());
