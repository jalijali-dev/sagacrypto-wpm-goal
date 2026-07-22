/* Sagagoal — Livescore page interactivity: client-side search + "Live"
   toggle filter over already-rendered fixture cards, a vanilla month
   calendar popup for date navigation, and (today only) a 45s auto-refresh
   poll against livescore-poll.php. Never calls any external API from the
   browser — livescore-poll.php only reads the local `fixtures` table.
   Shared across sports (football's livescore.php, NBA's nba.php) — the
   set of "live" status codes differs per sport (football: "1H,HT,2H,ET,P"
   strings; NBA: numeric "2"), so it's read from #livescore-list's
   data-live-statuses attribute rather than hardcoded, defaulting to
   football's set for backward compatibility. */
(function () {
  var list = document.getElementById("livescore-list");
  if (!list) { return; }

  var LIVE_STATUSES = (list.dataset.liveStatuses || "1H,HT,2H,ET,P").split(",");

  var searchInput = document.getElementById("livescore-search-input");
  var liveToggle = document.getElementById("livescore-live-toggle");
  var noMatchState = document.getElementById("livescore-no-match-state");
  var emptyState = document.getElementById("livescore-empty-state");

  function cards() {
    return Array.prototype.slice.call(list.querySelectorAll(".fixture-card"));
  }

  function applyFilters() {
    if (emptyState) { return; } // no fixtures at all for this date — nothing to filter
    var query = searchInput ? searchInput.value.trim().toLowerCase() : "";
    var liveOnly = liveToggle && liveToggle.getAttribute("aria-pressed") === "true";
    var anyVisible = false;

    list.querySelectorAll(".fixture-league-group").forEach(function (group) {
      var groupHasVisible = false;

      var roundGroups = group.querySelectorAll(".fixture-round-group");
      var scopes = roundGroups.length ? roundGroups : [group];
      scopes.forEach(function (scope) {
        var scopeHasVisible = false;
        scope.querySelectorAll(".fixture-card").forEach(function (card) {
          var matchesSearch = query === "" || (card.getAttribute("data-search") || "").indexOf(query) !== -1;
          var matchesLive = !liveOnly || LIVE_STATUSES.indexOf(card.getAttribute("data-status")) !== -1;
          var visible = matchesSearch && matchesLive;
          card.hidden = !visible;
          if (visible) { scopeHasVisible = true; }
        });
        if (scope !== group) { scope.hidden = !scopeHasVisible; }
        if (scopeHasVisible) { groupHasVisible = true; }
      });

      group.hidden = !groupHasVisible;
      if (groupHasVisible) { anyVisible = true; }
    });

    if (noMatchState) { noMatchState.hidden = anyVisible; }
  }

  if (searchInput) { searchInput.addEventListener("input", applyFilters); }

  if (liveToggle) {
    liveToggle.addEventListener("click", function () {
      var pressed = liveToggle.getAttribute("aria-pressed") === "true";
      liveToggle.setAttribute("aria-pressed", pressed ? "false" : "true");
      applyFilters();
    });
  }

  // ---- Calendar popup ----------------------------------------------
  var calBtn = document.getElementById("livescore-calendar-btn");
  var calPopup = document.getElementById("livescore-calendar-popup");

  if (calBtn && calPopup) {
    var selectedDate = calBtn.getAttribute("data-selected");
    var todayDate = calBtn.getAttribute("data-today");
    var urlTemplate = calBtn.getAttribute("data-url-template");
    var monthNames = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    var dayLabels = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

    function parseDate(str) {
      var parts = str.split("-");
      return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }

    function toDateStr(y, m, d) {
      var mm = String(m + 1).padStart(2, "0");
      var dd = String(d).padStart(2, "0");
      return y + "-" + mm + "-" + dd;
    }

    var viewDate = parseDate(selectedDate);
    viewDate.setDate(1);

    function renderCalendar() {
      var year = viewDate.getFullYear();
      var month = viewDate.getMonth();
      var firstWeekday = new Date(year, month, 1).getDay();
      var daysInMonth = new Date(year, month + 1, 0).getDate();

      var html = '<div class="livescore-calendar-popup__header">' +
        '<button type="button" class="livescore-calendar-popup__nav" data-cal-nav="-1" aria-label="Bulan sebelumnya">' + prevIcon() + '</button>' +
        '<span class="livescore-calendar-popup__title">' + monthNames[month] + " " + year + '</span>' +
        '<button type="button" class="livescore-calendar-popup__nav" data-cal-nav="1" aria-label="Bulan berikutnya">' + nextIcon() + '</button>' +
        '</div>';

      html += '<div class="livescore-calendar-popup__weekdays">';
      dayLabels.forEach(function (d) { html += "<span>" + d + "</span>"; });
      html += "</div>";

      html += '<div class="livescore-calendar-popup__days">';
      for (var i = 0; i < firstWeekday; i++) {
        html += '<span class="livescore-calendar-popup__day is-empty"></span>';
      }
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = toDateStr(year, month, d);
        var classes = "livescore-calendar-popup__day";
        if (dateStr === todayDate) { classes += " is-today"; }
        if (dateStr === selectedDate) { classes += " is-selected"; }
        html += '<button type="button" class="' + classes + '" data-cal-date="' + dateStr + '">' + d + "</button>";
      }
      html += "</div>";

      calPopup.innerHTML = html;
    }

    function prevIcon() {
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>';
    }
    function nextIcon() {
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>';
    }

    calPopup.addEventListener("click", function (e) {
      // Stop before any innerHTML mutation below detaches e.target — a
      // stale reference would fail the document handler's contains()
      // check and the popup would look like an outside click closed it.
      e.stopPropagation();
      var navBtn = e.target.closest("[data-cal-nav]");
      if (navBtn) {
        viewDate.setMonth(viewDate.getMonth() + parseInt(navBtn.getAttribute("data-cal-nav"), 10));
        renderCalendar();
        return;
      }
      var dayBtn = e.target.closest("[data-cal-date]");
      if (dayBtn) {
        window.location.href = urlTemplate.replace("__DATE__", dayBtn.getAttribute("data-cal-date"));
      }
    });

    calBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      var opening = calPopup.hidden;
      calPopup.hidden = !opening;
      calBtn.classList.toggle("is-active", opening);
      if (opening) { renderCalendar(); }
    });

    document.addEventListener("click", function (e) {
      if (!calPopup.hidden && !calPopup.contains(e.target) && e.target !== calBtn) {
        calPopup.hidden = true;
        calBtn.classList.remove("is-active");
      }
    });
  }

  // ---- Auto-refresh (today only) ------------------------------------
  if (list.getAttribute("data-poll") !== "1") { return; }

  function statusLabel(f) {
    if (f.status_short === "HT") { return "HT"; }
    if (f.elapsed !== null) { return f.elapsed + "'"; }
    return f.status_short;
  }

  function refresh() {
    fetch("livescore-poll.php", { headers: { Accept: "application/json" } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) { return; }
        var liveOnLoad = false;
        data.fixtures.forEach(function (f) {
          var card = list.querySelector('[data-fixture-id="' + f.id + '"]');
          if (!card) { return; }

          card.setAttribute("data-status", f.status_short);
          var isLive = LIVE_STATUSES.indexOf(f.status_short) !== -1;
          card.classList.toggle("is-live", isLive);
          if (isLive) { liveOnLoad = true; }

          if (f.home_score !== null && f.away_score !== null) {
            var scoreEl = card.querySelector('[data-field="score"]');
            if (scoreEl) {
              var spans = scoreEl.querySelectorAll("span:not(.fixture-card__score-sep)");
              if (spans.length === 2) {
                spans[0].textContent = f.home_score;
                spans[1].textContent = f.away_score;
              }
            }
          }

          var statusEl = card.querySelector('[data-field="status"]');
          if (statusEl && isLive) {
            statusEl.innerHTML =
              '<span class="fixture-status fixture-status--live"><span class="fixture-status__dot" aria-hidden="true"></span>' +
              statusLabel(f) + "</span>";
          }
        });

        if (liveToggle) {
          var count = data.fixtures.filter(function (f) { return LIVE_STATUSES.indexOf(f.status_short) !== -1; }).length;
          liveToggle.setAttribute("data-live-count", String(count));
          var countEl = liveToggle.querySelector(".livescore-live-toggle__count");
          if (countEl) { countEl.textContent = String(count); }
        }

        applyFilters();
      })
      .catch(function () { /* silent — next tick tries again */ });
  }

  setInterval(refresh, 45000);
})();
