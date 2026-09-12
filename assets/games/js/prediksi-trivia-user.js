/**
 * Public per-player history page (games/prediksi-trivia/user.php,
 * 12 Sep 2026 operator request) — reads `?code=` from the URL, fetches
 * api/game-user-history.php, renders nickname/total points/match
 * history. Deliberately NOT sharing code with prediksi-trivia.js: this
 * page has no nickname gate, no localStorage identity, no editing — it's
 * a pure read-only view of someone else's public data, simple enough
 * that pulling in the game's own (much bigger, stateful) script would
 * add more risk of accidentally wiring up something interactive here
 * than it would save.
 *
 * All text content here uses `textContent`, never `innerHTML`, for any
 * data field that ultimately comes from the database (nickname, league/
 * team names) — this page is public-facing and one of its own data
 * sources (custom/manual live-stream-style team names an admin could
 * theoretically type) isn't guaranteed to be free of HTML-special
 * characters, so escaping-by-construction here is deliberate, not an
 * oversight (see docs/DECISIONS.md and the site's own security audit,
 * 12 Sep 2026, for the same concern raised about prediksi-trivia.js's
 * "Tebakan Saya" box).
 */
(function () {
  'use strict';

  var loadingEl = document.getElementById('ptu-loading');
  var notFoundEl = document.getElementById('ptu-notfound');
  var profileEl = document.getElementById('ptu-profile');
  var nicknameEl = document.getElementById('ptu-nickname');
  var totalPointsEl = document.getElementById('ptu-total-points');
  var historyListEl = document.getElementById('ptu-history-list');
  var historyEmptyEl = document.getElementById('ptu-history-empty');

  function getCodeFromUrl() {
    try {
      return new URLSearchParams(window.location.search).get('code') || '';
    } catch (e) {
      return '';
    }
  }

  /** Builds one read-only match-history card — same visual language as
   * prediksi-trivia.js's fmtLocked() result line, but as its own
   * standalone card (this page has no live/editable predict-area). */
  function buildHistoryCard(item) {
    var card = document.createElement('div');
    card.className = 'wpm-pt-match';

    var meta = document.createElement('div');
    meta.className = 'wpm-pt-match__meta';
    var leagueSpan = document.createElement('span');
    leagueSpan.className = 'wpm-pt-match__league';
    leagueSpan.textContent = item.league_name || '';
    var timeSpan = document.createElement('span');
    timeSpan.className = 'wpm-pt-match__time';
    timeSpan.textContent = item.kickoff_at_wib || '';
    meta.appendChild(leagueSpan);
    meta.appendChild(timeSpan);

    var teams = document.createElement('div');
    teams.className = 'wpm-pt-match__teams';
    var homeSpan = document.createElement('span');
    homeSpan.className = 'wpm-pt-match__team';
    homeSpan.textContent = item.home_name || '';
    var vsSpan = document.createElement('span');
    vsSpan.className = 'wpm-pt-match__vs';
    vsSpan.textContent = 'vs';
    var awaySpan = document.createElement('span');
    awaySpan.className = 'wpm-pt-match__team';
    awaySpan.textContent = item.away_name || '';
    teams.appendChild(homeSpan);
    teams.appendChild(vsSpan);
    teams.appendChild(awaySpan);

    var result = document.createElement('div');
    result.className = 'wpm-pt-match__result';
    result.textContent =
      'Hasil: ' + item.home_score + ' - ' + item.away_score +
      ' · Tebakan: ' + item.predicted_home + ' - ' + item.predicted_away +
      ' · +' + item.points_awarded + ' poin';

    card.appendChild(meta);
    card.appendChild(teams);
    card.appendChild(result);
    return card;
  }

  function render(data) {
    loadingEl.hidden = true;
    nicknameEl.textContent = data.nickname;
    totalPointsEl.textContent = String(data.total_points);

    historyListEl.innerHTML = '';
    if (!Array.isArray(data.history) || data.history.length === 0) {
      historyEmptyEl.hidden = false;
    } else {
      historyEmptyEl.hidden = true;
      data.history.forEach(function (item) {
        historyListEl.appendChild(buildHistoryCard(item));
      });
    }
    profileEl.hidden = false;
  }

  function renderNotFound() {
    loadingEl.hidden = true;
    notFoundEl.hidden = false;
  }

  var code = getCodeFromUrl();
  if (code === '') {
    renderNotFound();
    return;
  }

  fetch('api/game-user-history.php?code=' + encodeURIComponent(code))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success || !data.data) {
        renderNotFound();
        return;
      }
      render(data.data);
    })
    .catch(function () {
      renderNotFound();
    });
})();
