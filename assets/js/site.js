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
  var themeBtn = document.getElementById("theme-toggle-btn");
  if (themeBtn) {
    themeBtn.addEventListener("click", function () {
      var current = document.documentElement.getAttribute("data-theme") === "light" ? "light" : "dark";
      var theme = current === "dark" ? "light" : "dark";
      document.documentElement.setAttribute("data-theme", theme);
      try { localStorage.setItem("sagagoal_theme", theme); } catch (e) { /* storage unavailable — theme just won't persist */ }
    });
  }

  /* Homepage "Muat Lebih Banyak" (28 Agu 2026) — replaces numbered
     pagination on index.php. Fetches api/load-more-articles.php (an HTML
     fragment, not JSON) and appends rows into #wpm-news-list. Button
     itself carries all the state (tab/sport/next page) via data-*
     attributes set server-side in index.php, so this needs no knowledge
     of the current URL's query string. */
  var loadMoreWrap = document.getElementById("wpm-load-more");
  var loadMoreBtn = document.getElementById("wpm-load-more-btn");
  var newsList = document.getElementById("wpm-news-list");
  if (loadMoreWrap && loadMoreBtn && newsList) {
    loadMoreBtn.addEventListener("click", function () {
      var tab = loadMoreWrap.getAttribute("data-tab") || "terbaru";
      var sport = loadMoreWrap.getAttribute("data-sport") || "";
      var nextPage = parseInt(loadMoreWrap.getAttribute("data-next-page") || "2", 10) || 2;

      var url = "api/load-more-articles.php?tab=" + encodeURIComponent(tab) + "&page=" + nextPage;
      if (sport !== "") { url += "&sport=" + encodeURIComponent(sport); }

      loadMoreBtn.disabled = true;
      loadMoreBtn.textContent = "Memuat...";

      fetch(url)
        .then(function (res) {
          var hasMore = res.headers.get("X-Has-More") === "1";
          return res.text().then(function (html) { return { html: html, hasMore: hasMore }; });
        })
        .then(function (result) {
          if (result.html.trim() !== "") {
            newsList.insertAdjacentHTML("beforeend", result.html);
          }
          if (result.hasMore) {
            loadMoreWrap.setAttribute("data-next-page", String(nextPage + 1));
            loadMoreBtn.disabled = false;
            loadMoreBtn.textContent = "Muat Lebih Banyak";
          } else {
            loadMoreWrap.remove();
          }
        })
        .catch(function () {
          // Network hiccup — let the visitor retry instead of silently
          // stranding them with a dead button.
          loadMoreBtn.disabled = false;
          loadMoreBtn.textContent = "Coba Lagi";
        });
    });
  }

  /* kategori.php "Muat Lebih Banyak" (7 Sep 2026) — same pattern as the
     homepage block just above, but for /berita (kategori.php's 4 modes:
     all/kategori/tag/league) via api/load-more-berita.php, appending into
     #wpm-berita-grid instead of #wpm-news-list. Uses an ABSOLUTE url
     (data-base + "/api/...") rather than the homepage's plain relative
     "api/..." — index.php only ever lives at the site root so a relative
     path always resolves correctly there, but kategori.php is also
     reachable through nested pretty URLs like /berita/kategori/<slug> or
     /berita/tag/<slug> (see .htaccess), where a relative "api/..." would
     wrongly resolve under /berita/... instead of the real site root.
     data-base carries wpm_base_path() from kategori.php (empty string at
     the domain root in production, e.g. "/wpm" for a local subfolder
     install) so this keeps working in both. */
  var beritaLoadMoreWrap = document.getElementById("wpm-berita-load-more");
  var beritaLoadMoreBtn = document.getElementById("wpm-berita-load-more-btn");
  var beritaGrid = document.getElementById("wpm-berita-grid");
  if (beritaLoadMoreWrap && beritaLoadMoreBtn && beritaGrid) {
    beritaLoadMoreBtn.addEventListener("click", function () {
      var base = beritaLoadMoreWrap.getAttribute("data-base") || "";
      var slug = beritaLoadMoreWrap.getAttribute("data-slug") || "";
      var tag = beritaLoadMoreWrap.getAttribute("data-tag") || "";
      var league = beritaLoadMoreWrap.getAttribute("data-league") || "0";
      var nextPage = parseInt(beritaLoadMoreWrap.getAttribute("data-next-page") || "2", 10) || 2;

      var url = base + "/api/load-more-berita.php?page=" + nextPage;
      if (slug !== "") { url += "&slug=" + encodeURIComponent(slug); }
      if (tag !== "") { url += "&tag=" + encodeURIComponent(tag); }
      if (league !== "0" && league !== "") { url += "&league=" + encodeURIComponent(league); }

      beritaLoadMoreBtn.disabled = true;
      beritaLoadMoreBtn.textContent = "Memuat...";

      fetch(url)
        .then(function (res) {
          var hasMore = res.headers.get("X-Has-More") === "1";
          return res.text().then(function (html) { return { html: html, hasMore: hasMore }; });
        })
        .then(function (result) {
          if (result.html.trim() !== "") {
            beritaGrid.insertAdjacentHTML("beforeend", result.html);
          }
          if (result.hasMore) {
            beritaLoadMoreWrap.setAttribute("data-next-page", String(nextPage + 1));
            beritaLoadMoreBtn.disabled = false;
            beritaLoadMoreBtn.textContent = "Muat Lebih Banyak";
          } else {
            beritaLoadMoreWrap.remove();
          }
        })
        .catch(function () {
          beritaLoadMoreBtn.disabled = false;
          beritaLoadMoreBtn.textContent = "Coba Lagi";
        });
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
