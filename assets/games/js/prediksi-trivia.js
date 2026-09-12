/**
 * Prediksi & Trivia — Games Hub's fifth game (8 Sep 2026, brief "Games
 * Hub #5: Prediksi & Trivia"). Vanilla JS, no engine/library. Unlike
 * the other 4 games' JS files, this one talks to a real backend (see
 * includes/GamesShared.php's docblock for why — score-prediction
 * outcomes aren't knowable until a real match finishes hours later).
 *
 * Identity: nickname + a client-generated browser_id (UUID), both in
 * localStorage under a `pt_`-prefixed key (distinct from any other
 * game's keys — none of the other 4 use localStorage at all, so there
 * is no actual collision risk today, but the prefix keeps it that way
 * going forward). No accounts, no login, no cookies read/set by this
 * file. A new browser/incognito/cleared localStorage = a new player —
 * expected behavior, not a bug (operator-confirmed design decision).
 *
 * Two tabs, one page (operator decision, confirmed before the brief was
 * written): "Prediksi Skor Harian" (talks to the backend) and "Trivia
 * Cepat" (100% client-side during play, same as quiz-bola, only
 * reporting its FINAL session score to the backend once at the end).
 *
 * Trivia question bank: copied from quiz-bola.js's QUESTION_BANK (per
 * the brief's "jangan nulis ulang dari nol" — reusing the actual
 * question content) rather than imported/shared — same "each game file
 * stays fully independent" convention as every other game here (see
 * e.g. the audio-synth pattern duplicated across all 5 files instead of
 * a shared module). The `difficulty` tag on each question is carried
 * over from quiz-bola.js but unused here — Trivia Cepat deliberately
 * has one fixed pace (10s/question) for everyone, no difficulty picker.
 */
(function () {
  'use strict';

  // ==================================================================
  // Identity (nickname + browser_id)
  // ==================================================================
  var LS_NICKNAME = 'pt_nickname';
  var LS_BROWSER_ID = 'pt_browser_id';

  function getBrowserId() {
    var id = localStorage.getItem(LS_BROWSER_ID);
    if (id) { return id; }
    id = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : (
      'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = (Math.random() * 16) | 0;
        var v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
      })
    );
    try { localStorage.setItem(LS_BROWSER_ID, id); } catch (e) { /* localStorage unavailable — session-only fallback below */ }
    return id;
  }

  function getNickname() {
    try { return localStorage.getItem(LS_NICKNAME) || ''; } catch (e) { return ''; }
  }
  function setNickname(name) {
    try { localStorage.setItem(LS_NICKNAME, name); } catch (e) { /* ignore — nickname just won't persist */ }
  }

  var browserId = null; // set once, lazily, on first real use (see ensureIdentity())
  var nickname = getNickname();

  // ==================================================================
  // DOM refs
  // ==================================================================
  var nicknameGate = document.getElementById('pt-nickname-gate');
  var nicknameInput = document.getElementById('pt-nickname-input');
  var nicknameError = document.getElementById('pt-nickname-error');
  var nicknameSaveBtn = document.getElementById('pt-nickname-save-btn');
  var mainEl = document.getElementById('pt-main');
  var whoamiEl = document.getElementById('pt-whoami');
  var muteBtn = document.getElementById('pt-mute-btn');

  var tabPredictBtn = document.getElementById('pt-tab-predict-btn');
  var tabTriviaBtn = document.getElementById('pt-tab-trivia-btn');
  var tabpanelPredict = document.getElementById('pt-tabpanel-predict');
  var tabpanelTrivia = document.getElementById('pt-tabpanel-trivia');

  var matchListEl = document.getElementById('pt-match-list');

  // 9 Sep 2026 — "Tebakan Saya" recap box (operator request): lists just
  // MY OWN saved predictions across the whole fetched window, editable
  // inline. See fillPredictArea()/renderMyPredictions() below.
  var myPredictionsToggle = document.getElementById('pt-my-predictions-toggle');
  var myPredictionsEl = document.getElementById('pt-my-predictions');
  var myPredictionsEmptyEl = document.getElementById('pt-my-predictions-empty');
  var myPredictionsListEl = document.getElementById('pt-my-predictions-list');
  var myPredictionsLoaded = false;
  var lastFixturesData = []; // populated by loadFixtures(), read by renderMyPredictions()

  var leaderboardToggle = document.getElementById('pt-leaderboard-toggle');
  var leaderboardEl = document.getElementById('pt-leaderboard');
  var leaderboardLoadingEl = document.getElementById('pt-leaderboard-loading');
  var leaderboardListEl = document.getElementById('pt-leaderboard-list');

  // ==================================================================
  // Audio — same synthesized-tone pattern as the other 4 games
  // (duplicated by design, not shared — see file docblock).
  // ==================================================================
  var audioCtx = null;
  var audioEnabled = true;

  function initAudio() {
    if (audioCtx) {
      if (audioCtx.state === 'suspended') { audioCtx.resume(); }
      return;
    }
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { return; }
    try { audioCtx = new AC(); } catch (e) { audioCtx = null; }
  }

  function playTone(freq, duration, type, peakVolume, delay) {
    if (!audioEnabled || !audioCtx) { return; }
    var t0 = audioCtx.currentTime + (delay || 0);
    var osc = audioCtx.createOscillator();
    var gain = audioCtx.createGain();
    osc.type = type || 'square';
    if (Array.isArray(freq)) {
      osc.frequency.setValueAtTime(freq[0], t0);
      osc.frequency.exponentialRampToValueAtTime(Math.max(1, freq[1]), t0 + duration);
    } else {
      osc.frequency.setValueAtTime(freq, t0);
    }
    gain.gain.setValueAtTime(0, t0);
    gain.gain.linearRampToValueAtTime(peakVolume, t0 + 0.008);
    gain.gain.exponentialRampToValueAtTime(0.001, t0 + duration);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start(t0);
    osc.stop(t0 + duration + 0.02);
  }

  var sfx = {
    correct: function () {
      playTone(659.25, 0.1, 'triangle', 0.12, 0);
      playTone(987.77, 0.14, 'triangle', 0.12, 0.09);
    },
    wrong: function () { playTone([300, 120], 0.22, 'sawtooth', 0.09, 0); },
    timeout: function () { playTone(180, 0.28, 'sawtooth', 0.08, 0); },
    tick: function () { playTone(880, 0.04, 'square', 0.03, 0); },
    finish: function () {
      playTone(523.25, 0.12, 'triangle', 0.12, 0);
      playTone(659.25, 0.12, 'triangle', 0.12, 0.11);
      playTone(783.99, 0.12, 'triangle', 0.12, 0.22);
      playTone(1046.5, 0.24, 'triangle', 0.13, 0.33);
    },
    predictSaved: function () { playTone([440, 660], 0.12, 'square', 0.08, 0); },
  };

  function setMuteButtonUi() {
    if (!muteBtn) { return; }
    muteBtn.textContent = audioEnabled ? '🔊' : '🔇';
    muteBtn.setAttribute('aria-pressed', audioEnabled ? 'false' : 'true');
    muteBtn.setAttribute('aria-label', audioEnabled ? 'Matikan suara' : 'Nyalakan suara');
  }
  if (muteBtn) {
    muteBtn.addEventListener('click', function () {
      audioEnabled = !audioEnabled;
      setMuteButtonUi();
      if (audioEnabled) { initAudio(); }
    });
  }
  setMuteButtonUi();

  // ==================================================================
  // Nickname gate
  // ==================================================================
  function showNicknameGate() {
    nicknameGate.hidden = false;
    mainEl.hidden = true;
    nicknameInput.focus();
  }

  function showMain() {
    nicknameGate.hidden = true;
    mainEl.hidden = false;
    whoamiEl.textContent = 'Main sebagai: ' + nickname;
    loadFixtures();
  }

  function validateNicknameClientSide(raw) {
    var trimmed = raw.replace(/\s+/g, ' ').trim();
    if (trimmed.length < 2 || trimmed.length > 30) {
      return { ok: false, message: 'Nickname harus 2-30 karakter.' };
    }
    return { ok: true, value: trimmed };
  }

  nicknameSaveBtn.addEventListener('click', function () {
    var result = validateNicknameClientSide(nicknameInput.value || '');
    if (!result.ok) {
      nicknameError.textContent = result.message;
      nicknameError.hidden = false;
      return;
    }
    nicknameError.hidden = true;
    nickname = result.value;
    setNickname(nickname);
    getBrowserId(); // makes sure browser_id exists before any API call
    showMain();
  });
  nicknameInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { nicknameSaveBtn.click(); }
  });

  // ==================================================================
  // Tabs
  // ==================================================================
  function switchTab(which) {
    var toPredict = which === 'predict';
    tabPredictBtn.classList.toggle('is-active', toPredict);
    tabTriviaBtn.classList.toggle('is-active', !toPredict);
    tabPredictBtn.setAttribute('aria-selected', toPredict ? 'true' : 'false');
    tabTriviaBtn.setAttribute('aria-selected', !toPredict ? 'true' : 'false');
    tabpanelPredict.hidden = !toPredict;
    tabpanelTrivia.hidden = toPredict;
  }
  tabPredictBtn.addEventListener('click', function () { switchTab('predict'); });
  tabTriviaBtn.addEventListener('click', function () { switchTab('trivia'); });

  // ==================================================================
  // Prediksi Skor Harian
  // ==================================================================

  /**
   * Escapes a value before it's concatenated into an innerHTML string
   * (12 Sep 2026 security audit finding #9) — same implementation as
   * cms-admin/assets/js/admin.js's own escapeHtml(), duplicated here
   * rather than shared (this file has no dependency on any admin-only
   * asset, and a 3-line helper isn't worth introducing one just for
   * this). Used in renderMyPredictions() below for league_name/
   * home_name/away_name/kickoff_at_wib — those come from the API
   * response (api/game-fixtures-today.php, ultimately teams/leagues
   * synced from API-Football) rather than direct user input, so the
   * practical risk today is low, but nothing here actually guarantees
   * that data is HTML-safe, and the numeric fields elsewhere in this
   * file (scores, points) don't need this at all since they're always
   * (int)-cast server-side before reaching this JS.
   */
  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtLocked(fixture) {
    if (fixture.status_short === 'FT' || fixture.status_short === 'AET' || fixture.status_short === 'PEN') {
      if (fixture.my_prediction && fixture.my_prediction.points_awarded !== null) {
        return '<div class="wpm-pt-match__result">Hasil: <strong>' + fixture.home_score + ' - ' + fixture.away_score + '</strong>'
          + ' &middot; Tebakanmu: ' + fixture.my_prediction.predicted_home + ' - ' + fixture.my_prediction.predicted_away
          + ' &middot; <span class="wpm-pt-match__points">+' + fixture.my_prediction.points_awarded + ' poin</span></div>';
      }
      if (fixture.my_prediction) {
        return '<div class="wpm-pt-match__result">Hasil: <strong>' + fixture.home_score + ' - ' + fixture.away_score + '</strong> &middot; Menunggu penilaian…</div>';
      }
      return '<div class="wpm-pt-match__result">Hasil: <strong>' + fixture.home_score + ' - ' + fixture.away_score + '</strong> &middot; Kamu tidak menebak pertandingan ini.</div>';
    }
    return '<div class="wpm-pt-match__locked">🔒 Terkunci — nunggu hasil</div>';
  }

  /**
   * Fills ANY predict-area container (main list card OR the "Tebakan
   * Saya" recap box below) with either the locked/result display or the
   * editable input+submit form — same logic, just no longer tied to
   * looking the container up inside matchListEl specifically. `onSaved`
   * is an optional extra callback (besides the built-in feedback text)
   * so callers that need to refresh a SECOND copy of the same fixture's
   * UI elsewhere on the page (recap box vs. main list) can do so without
   * this function needing to know both places exist.
   */
  function fillPredictArea(container, fixture, onSaved) {
    if (!container) { return; }

    if (fixture.is_locked) {
      container.innerHTML = fmtLocked(fixture);
      return;
    }

    var prevHome = fixture.my_prediction ? fixture.my_prediction.predicted_home : '';
    var prevAway = fixture.my_prediction ? fixture.my_prediction.predicted_away : '';
    var label = fixture.my_prediction ? 'Ubah Tebakan' : 'Kunci Tebakan';

    container.innerHTML =
      '<div class="wpm-pt-match__inputs">' +
      '<input type="number" min="0" max="20" inputmode="numeric" class="wpm-pt-match__score-input" data-role="home-input" value="' + prevHome + '" aria-label="Skor tim tuan rumah">' +
      '<span class="wpm-pt-match__dash">-</span>' +
      '<input type="number" min="0" max="20" inputmode="numeric" class="wpm-pt-match__score-input" data-role="away-input" value="' + prevAway + '" aria-label="Skor tim tamu">' +
      '</div>' +
      '<button type="button" class="wpm-pt-match__submit-btn" data-role="submit-btn">' + label + '</button>' +
      '<p class="wpm-pt-match__feedback" data-role="feedback" hidden></p>';

    var homeInput = container.querySelector('[data-role="home-input"]');
    var awayInput = container.querySelector('[data-role="away-input"]');
    var submitBtn = container.querySelector('[data-role="submit-btn"]');
    var feedbackEl = container.querySelector('[data-role="feedback"]');

    submitBtn.addEventListener('click', function () {
      var homeVal = homeInput.value;
      var awayVal = awayInput.value;
      if (homeVal === '' || awayVal === '') {
        feedbackEl.textContent = 'Isi kedua skor dulu.';
        feedbackEl.hidden = false;
        return;
      }
      submitBtn.disabled = true;
      feedbackEl.hidden = true;
      var homeNum = parseInt(homeVal, 10);
      var awayNum = parseInt(awayVal, 10);
      submitPrediction(fixture.id, homeNum, awayNum, function (ok, message) {
        submitBtn.disabled = false;
        if (ok) {
          submitBtn.textContent = 'Ubah Tebakan';
          feedbackEl.textContent = 'Tersimpan!';
          feedbackEl.className = 'wpm-pt-match__feedback is-ok';
          feedbackEl.hidden = false;
          sfx.predictSaved();
          // Keep our in-memory copy in sync so re-opening "Tebakan Saya"
          // (or re-editing right after saving) shows the NEW value
          // without needing a full reload/refetch.
          fixture.my_prediction = { predicted_home: homeNum, predicted_away: awayNum, points_awarded: null };
          if (typeof onSaved === 'function') { onSaved(fixture); }
        } else {
          feedbackEl.textContent = message || 'Gagal menyimpan.';
          feedbackEl.className = 'wpm-pt-match__feedback is-error';
          feedbackEl.hidden = false;
        }
      });
    });
  }

  function renderMatchCard(fixture) {
    var container = matchListEl.querySelector('.wpm-pt-match[data-fixture-id="' + fixture.id + '"] [data-role="predict-area"]');
    fillPredictArea(container, fixture, function () {
      // Editing from the MAIN list — if the "Tebakan Saya" box has
      // already been opened/built at least once, refresh it too so it
      // doesn't show a stale score after a save made from the other box.
      if (myPredictionsLoaded) { renderMyPredictions(); }
    });
  }

  /**
   * "Tebakan Saya" recap box — every fixture (from the same H+2 window
   * already fetched by loadFixtures()) where `my_prediction` isn't null,
   * regardless of locked/open state, so the user has ONE place to see
   * everything they've saved instead of hunting through the full list
   * above. Each entry reuses fillPredictArea() — editable inline here
   * too, as long as that fixture isn't locked yet.
   */
  function renderMyPredictions() {
    if (!myPredictionsListEl) { return; }
    var mine = lastFixturesData.filter(function (fx) { return !!fx.my_prediction; });

    myPredictionsListEl.innerHTML = '';
    myPredictionsEmptyEl.hidden = mine.length > 0;
    if (mine.length === 0) { return; }

    mine.forEach(function (fixture) {
      var card = document.createElement('div');
      card.className = 'wpm-pt-match';
      card.setAttribute('data-fixture-id', fixture.id);
      card.innerHTML =
        '<div class="wpm-pt-match__meta">' +
        '<span class="wpm-pt-match__league">' + escapeHtml(fixture.league_name || '') + '</span>' +
        '<span class="wpm-pt-match__time">' + escapeHtml(fixture.kickoff_at_wib || '') + ' WIB</span>' +
        '</div>' +
        '<div class="wpm-pt-match__teams">' +
        '<span class="wpm-pt-match__team">' + escapeHtml(fixture.home_name || '') + '</span>' +
        '<span class="wpm-pt-match__vs">vs</span>' +
        '<span class="wpm-pt-match__team">' + escapeHtml(fixture.away_name || '') + '</span>' +
        '</div>' +
        '<div class="wpm-pt-match__predict-area" data-role="predict-area"></div>';
      myPredictionsListEl.appendChild(card);

      var container = card.querySelector('[data-role="predict-area"]');
      fillPredictArea(container, fixture, function () {
        // Editing from the RECAP box — refresh the main list's copy of
        // this same fixture too, so the two boxes never disagree.
        renderMatchCard(fixture);
      });
    });
  }

  if (myPredictionsToggle) {
    myPredictionsToggle.addEventListener('click', function () {
      var willShow = myPredictionsEl.hidden;
      myPredictionsEl.hidden = !willShow;
      myPredictionsToggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
      myPredictionsToggle.textContent = willShow ? 'Sembunyikan Tebakan Saya' : '📝 Tebakan Saya';
      if (willShow) {
        myPredictionsLoaded = true;
        renderMyPredictions();
      }
    });
  }

  function submitPrediction(fixtureId, home, away, done) {
    fetch('api/game-predict.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        browser_id: getBrowserId(),
        nickname: nickname,
        fixture_id: fixtureId,
        predicted_home: home,
        predicted_away: away,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) { done(!!data.success, data.message); })
      .catch(function () { done(false, 'Koneksi gagal, coba lagi.'); });
  }

  function loadFixtures() {
    if (!matchListEl) { return; } // no matches today — nothing to hydrate
    fetch('api/game-fixtures-today.php?browser_id=' + encodeURIComponent(getBrowserId()))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success || !Array.isArray(data.fixtures)) { return; }
        lastFixturesData = data.fixtures;
        data.fixtures.forEach(renderMatchCard);
        // If the recap box was already open (rare — only via a fast
        // reload race), keep it in sync with the freshly fetched data.
        if (myPredictionsLoaded) { renderMyPredictions(); }
      })
      .catch(function () {
        // Fixture times/names from SSR stay visible; predict-areas just
        // keep showing "Memuat…" — acceptable degrade, user can reload.
      });
  }

  // ==================================================================
  // Trivia Cepat
  // ==================================================================
  var triviaStartPanel = document.getElementById('pt-trivia-start');
  var triviaStartBtn = document.getElementById('pt-trivia-start-btn');
  var triviaBoard = document.getElementById('pt-trivia-board');
  var triviaEndPanel = document.getElementById('pt-trivia-end');
  var triviaQuestionCountEl = document.getElementById('pt-trivia-question-count');
  var triviaScoreEl = document.getElementById('pt-trivia-score');
  var triviaStreakBadge = document.getElementById('pt-trivia-streak-badge');
  var triviaStreakValueEl = document.getElementById('pt-trivia-streak-value');
  var triviaTimerFillEl = document.getElementById('pt-trivia-timer-fill');
  var triviaQuestionTextEl = document.getElementById('pt-trivia-question-text');
  var triviaOptionsEl = document.getElementById('pt-trivia-options');
  var triviaEndScoreEl = document.getElementById('pt-trivia-end-score');
  var triviaEndBreakdownEl = document.getElementById('pt-trivia-end-breakdown');
  var triviaPlayAgainBtn = document.getElementById('pt-trivia-play-again-btn');

  // Copied from quiz-bola.js's QUESTION_BANK (3 Sep 2026 bank, 92
  // questions) — see file docblock for why this is a copy, not a shared
  // import. `difficulty` tags are carried over but unused here.
  var TRIVIA_QUESTIONS = [
    { q: 'Berapa jumlah pemain inti (di lapangan) satu tim sepak bola?', options: ['9', '10', '11', '12'], correctIndex: 2 },
    { q: 'Berapa lama durasi normal satu babak pertandingan sepak bola?', options: ['30 menit', '45 menit', '60 menit', '90 menit'], correctIndex: 1 },
    { q: 'Kartu apa yang diberikan wasit sebagai peringatan sebelum pemain dikeluarkan?', options: ['Kartu putih', 'Kartu kuning', 'Kartu biru', 'Kartu hijau'], correctIndex: 1 },
    { q: 'Berapa kartu kuning yang membuat pemain otomatis terkena kartu merah?', options: ['1', '2', '3', '4'], correctIndex: 1 },
    { q: 'Apa nama tendangan bebas tanpa halangan pemain lawan dari titik penalti?', options: ['Tendangan sudut', 'Tendangan penalti', 'Tendangan gawang', 'Lemparan ke dalam'], correctIndex: 1 },
    { q: 'Pelanggaran apa yang membuat lawan mendapat lemparan ke dalam?', options: ['Bola keluar dari garis gawang', 'Bola keluar dari garis samping', 'Handball', 'Offside'], correctIndex: 1 },
    { q: 'Apa istilah untuk posisi pemain yang berada lebih dekat ke gawang lawan dibanding bola dan pemain lawan terakhir?', options: ['Offside', 'Onside', 'Kickoff', 'Overlap'], correctIndex: 0 },
    { q: 'Berapa jarak titik penalti dari garis gawang (dalam meter, standar FIFA)?', options: ['9 meter', '11 meter', '13 meter', '16 meter'], correctIndex: 1 },
    { q: 'Apa sebutan untuk babak tambahan waktu setelah 90 menit berakhir seri di pertandingan sistem gugur?', options: ['Injury time', 'Extra time', 'Stoppage time', 'Golden goal'], correctIndex: 1 },
    { q: 'Siapa yang berhak mengganti pemain selama pertandingan berlangsung?', options: ['Kapten tim', 'Wasit', 'Pelatih', 'Wasit garis'], correctIndex: 2 },
    { q: 'Berapa jumlah pemain minimal agar sebuah tim tetap boleh melanjutkan pertandingan?', options: ['5', '6', '7', '8'], correctIndex: 2 },
    { q: 'Apa nama garis tengah lapangan yang membagi lapangan jadi dua bagian sama besar?', options: ['Garis gawang', 'Garis tengah', 'Garis penalti', 'Garis sudut'], correctIndex: 1 },
    { q: 'Negara mana yang menjadi juara Piala Dunia FIFA pertama tahun 1930?', options: ['Brasil', 'Uruguay', 'Argentina', 'Italia'], correctIndex: 1 },
    { q: 'Negara mana yang paling banyak juara Piala Dunia FIFA (hingga 2026)?', options: ['Jerman', 'Argentina', 'Italia', 'Brasil'], correctIndex: 3 },
    { q: 'Piala Dunia FIFA 2022 diselenggarakan di negara mana?', options: ['Rusia', 'Qatar', 'Brasil', 'Jepang & Korea Selatan'], correctIndex: 1 },
    { q: 'Siapa yang menjadi juara Piala Dunia FIFA 2022?', options: ['Prancis', 'Brasil', 'Argentina', 'Kroasia'], correctIndex: 2 },
    { q: 'Piala Dunia FIFA 2018 diselenggarakan di negara mana?', options: ['Rusia', 'Jerman', 'Qatar', 'Afrika Selatan'], correctIndex: 0 },
    { q: 'Negara mana yang menjadi tuan rumah Piala Dunia FIFA 2014?', options: ['Meksiko', 'Brasil', 'Chile', 'Kolombia'], correctIndex: 1 },
    { q: 'Berapa tahun sekali Piala Dunia FIFA digelar?', options: ['2 tahun', '3 tahun', '4 tahun', '5 tahun'], correctIndex: 2 },
    { q: 'Siapa pemain yang mencetak "Hand of God" di Piala Dunia 1986?', options: ['Pele', 'Diego Maradona', 'Zico', 'Michel Platini'], correctIndex: 1 },
    { q: 'Siapa pencetak gol terbanyak sepanjang sejarah Piala Dunia FIFA (hingga 2026)?', options: ['Miroslav Klose', 'Lionel Messi', 'Kylian Mbappe', 'Ronaldo Nazario'], correctIndex: 2 },
    { q: 'Trofi apa yang diberikan pada juara Piala Dunia FIFA?', options: ['Piala Champions', 'Piala Jules Rimet (nama trofi saat ini: FIFA World Cup Trophy)', 'Ballon d\'Or', 'Piala Toyota'], correctIndex: 1 },
    { q: 'Manchester United bermain di liga mana?', options: ['La Liga', 'Serie A', 'Premier League', 'Bundesliga'], correctIndex: 2 },
    { q: 'Real Madrid dan Barcelona bermain di liga mana?', options: ['Premier League', 'La Liga', 'Ligue 1', 'Serie A'], correctIndex: 1 },
    { q: 'Bayern Munich adalah klub sepak bola dari negara mana?', options: ['Austria', 'Belanda', 'Jerman', 'Swiss'], correctIndex: 2 },
    { q: 'Klub mana yang berjuluk "The Old Lady" di Serie A Italia?', options: ['AC Milan', 'Inter Milan', 'Juventus', 'AS Roma'], correctIndex: 2 },
    { q: 'Paris Saint-Germain (PSG) bermain di liga sepak bola negara mana?', options: ['Belgia', 'Prancis', 'Swiss', 'Monako'], correctIndex: 1 },
    { q: 'Klub mana yang punya rekor juara Liga Champions UEFA terbanyak?', options: ['AC Milan', 'Liverpool', 'Bayern Munich', 'Real Madrid'], correctIndex: 3 },
    { q: 'Apa julukan Liverpool FC?', options: ['The Gunners', 'The Reds', 'The Blues', 'The Toffees'], correctIndex: 1 },
    { q: 'Apa julukan Arsenal FC?', options: ['The Gunners', 'The Reds', 'The Citizens', 'The Hammers'], correctIndex: 0 },
    { q: 'Apa julukan Manchester City?', options: ['The Gunners', 'The Toffees', 'The Citizens', 'The Reds'], correctIndex: 2 },
    { q: 'Klub mana yang berbasis di kota Milan dan berjuluk "Nerazzurri"?', options: ['AC Milan', 'Inter Milan', 'Juventus', 'Napoli'], correctIndex: 1 },
    { q: 'AC Milan dan Inter Milan berbagi stadion yang bernama?', options: ['San Siro', 'Allianz Stadium', 'Stadio Olimpico', 'Camp Nou'], correctIndex: 0 },
    { q: 'Stadion Camp Nou adalah kandang dari klub?', options: ['Real Madrid', 'Atletico Madrid', 'Barcelona', 'Sevilla'], correctIndex: 2 },
    { q: 'Cristiano Ronaldo berasal dari negara mana?', options: ['Spanyol', 'Brasil', 'Portugal', 'Argentina'], correctIndex: 2 },
    { q: 'Lionel Messi berasal dari negara mana?', options: ['Argentina', 'Uruguay', 'Spanyol', 'Kolombia'], correctIndex: 0 },
    { q: 'Siapa pemain yang identik dengan julukan "CR7"?', options: ['Cristiano Ronaldo', 'Robert Lewandowski', 'Karim Benzema', 'Neymar Jr'], correctIndex: 0 },
    { q: 'Neymar Jr adalah pemain berasal dari negara?', options: ['Argentina', 'Portugal', 'Brasil', 'Uruguay'], correctIndex: 2 },
    { q: 'Siapa legenda sepak bola Brasil yang memenangkan 3 Piala Dunia sebagai pemain?', options: ['Ronaldinho', 'Pele', 'Zico', 'Romario'], correctIndex: 1 },
    { q: 'Penghargaan individu tahunan apa yang diberikan kepada pemain terbaik dunia oleh France Football?', options: ['FIFA Puskas Award', 'Ballon d\'Or', 'The Best FIFA Award', 'Golden Boot'], correctIndex: 1 },
    { q: 'Penghargaan apa yang diberikan untuk pencetak gol terbanyak di sebuah kompetisi/musim?', options: ['Golden Ball', 'Golden Glove', 'Golden Boot', 'Golden Whistle'], correctIndex: 2 },
    { q: 'Posisi pemain yang tugas utamanya menjaga gawang disebut?', options: ['Bek', 'Gelandang', 'Penjaga gawang (kiper)', 'Penyerang'], correctIndex: 2 },
    { q: 'Apa sebutan untuk pemain yang mencetak 3 gol dalam satu pertandingan?', options: ['Double', 'Hat-trick', 'Triple kill', 'Brace'], correctIndex: 1 },
    { q: 'Apa sebutan untuk pemain yang mencetak 2 gol dalam satu pertandingan?', options: ['Brace', 'Hat-trick', 'Double kick', 'Assist ganda'], correctIndex: 0 },
    { q: 'Zinedine Zidane pernah menjadi pelatih sukses di klub?', options: ['Barcelona', 'Real Madrid', 'PSG', 'Juventus'], correctIndex: 1 },
    { q: 'Pep Guardiola saat ini (2026) melatih klub?', options: ['Bayern Munich', 'Barcelona', 'Manchester City', 'PSG'], correctIndex: 2 },
    { q: 'Erling Haaland adalah penyerang berasal dari negara?', options: ['Swedia', 'Denmark', 'Norwegia', 'Finlandia'], correctIndex: 2 },
    { q: 'Kylian Mbappe adalah pemain berasal dari negara?', options: ['Belgia', 'Prancis', 'Kamerun', 'Senegal'], correctIndex: 1 },
    { q: 'Kompetisi klub antarnegara Eropa paling prestisius bernama?', options: ['UEFA Europa League', 'UEFA Champions League', 'UEFA Conference League', 'UEFA Super Cup'], correctIndex: 1 },
    { q: 'Badan yang mengatur sepak bola dunia bernama?', options: ['UEFA', 'FIFA', 'IOC', 'CONCACAF'], correctIndex: 1 },
    { q: 'Badan yang mengatur sepak bola di kawasan Eropa bernama?', options: ['FIFA', 'AFC', 'UEFA', 'CAF'], correctIndex: 2 },
    { q: 'Badan yang mengatur sepak bola di kawasan Asia (termasuk Indonesia) bernama?', options: ['UEFA', 'CONMEBOL', 'AFC', 'CAF'], correctIndex: 2 },
    { q: 'Apa nama liga sepak bola tertinggi di Indonesia saat ini?', options: ['Liga 2', 'Liga 1', 'Liga Super Indonesia', 'Divisi Utama'], correctIndex: 1 },
    { q: 'Timnas Indonesia dijuluki dengan sebutan?', options: ['Garuda', 'Elang', 'Harimau', 'Singa'], correctIndex: 0 },
    { q: 'Warna kebesaran jersey kandang Timnas Indonesia adalah?', options: ['Biru', 'Merah', 'Putih', 'Hijau'], correctIndex: 1 },
    { q: 'Klub sepak bola mana yang berbasis di Jakarta dan salah satu klub tersukses di Liga 1?', options: ['Arema FC', 'Persib Bandung', 'Persija Jakarta', 'PSM Makassar'], correctIndex: 2 },
    { q: 'Persib adalah klub sepak bola yang berbasis di kota?', options: ['Surabaya', 'Bandung', 'Malang', 'Medan'], correctIndex: 1 },
    { q: 'Kompetisi sepak bola tingkat Asia untuk klub, setara Liga Champions Eropa, bernama?', options: ['AFF Cup', 'AFC Champions League', 'Asian Cup', 'SEA Games'], correctIndex: 1 },
    { q: 'Turnamen sepak bola antarnegara Asia Tenggara bernama?', options: ['AFC Cup', 'AFF Championship', 'Asian Games', 'Piala Presiden'], correctIndex: 1 },
    { q: 'Siapa pemain naturalisasi kelahiran Belanda yang memperkuat lini pertahanan Timnas Indonesia era 2020-an?', options: ['Jordi Amat', 'Sandy Walsh', 'Shayne Pattynama', 'Jay Idzes'], correctIndex: 3 },
    { q: 'Apa nama stadion utama yang sering dipakai Timnas Indonesia bertanding di Jakarta?', options: ['Stadion Si Jalak Harupat', 'Gelora Bung Karno', 'Stadion Kanjuruhan', 'Stadion Manahan'], correctIndex: 1 },
    { q: 'Apa istilah untuk babak adu penalti untuk menentukan pemenang setelah hasil seri?', options: ['Extra time', 'Golden goal', 'Adu penalti (penalty shoot-out)', 'Sudden death'], correctIndex: 2 },
    { q: 'Wasit menggunakan apa untuk memulai/menghentikan pertandingan?', options: ['Bendera', 'Peluit', 'Bel', 'Terompet'], correctIndex: 1 },
    { q: 'Petugas yang membantu wasit utama di sisi lapangan dan mengangkat bendera untuk offside disebut?', options: ['Wasit cadangan', 'Wasit garis (asisten wasit)', 'Manajer pertandingan', 'Pengawas pertandingan'], correctIndex: 1 },
    { q: 'Teknologi yang digunakan wasit untuk meninjau ulang keputusan kontroversial disebut?', options: ['GPS Tracking', 'VAR (Video Assistant Referee)', 'Hawk-Eye Radar', 'Goal Sensor Chip'], correctIndex: 1 },
    { q: 'Formasi 4-4-2 dalam sepak bola merujuk pada susunan pemain apa?', options: ['4 kiper, 4 bek, 2 penyerang', '4 bek, 4 gelandang, 2 penyerang', '4 penyerang, 4 gelandang, 2 bek', '4 bek, 2 gelandang, 4 penyerang'], correctIndex: 1 },
    { q: 'David Beckham terkenal karena keahliannya dalam mengeksekusi tendangan?', options: ['Tendangan penalti', 'Tendangan bebas (free-kick)', 'Tendangan gawang', 'Tendangan sudut'], correctIndex: 1 },
    { q: 'Ban yang dikenakan di lengan pemain untuk menandakan dia adalah kapten tim disebut?', options: ['Ban kapten', 'Ban lengan', 'Ban pelatih', 'Ban wasit'], correctIndex: 0 },
    { q: 'Selain kiper di kotak penaltinya sendiri, bagian tubuh apa yang tidak boleh dipakai mengontrol bola secara sengaja?', options: ['Kaki', 'Kepala', 'Tangan', 'Dada'], correctIndex: 2 },
    { q: 'Berapa jumlah wasit utama yang memimpin jalannya satu pertandingan sepak bola resmi?', options: ['1', '2', '3', '4'], correctIndex: 0 },
    { q: 'Apa istilah untuk operan/umpan terakhir dari rekan setim yang langsung menghasilkan gol?', options: ['Assist', 'Cross', 'Through pass', 'Deflection'], correctIndex: 0 },
  ];

  var QUESTIONS_PER_SESSION = 10;
  var TIMER_MS = 10000; // fixed 10s/question, no difficulty picker (per brief)
  var BASE_POINTS = 100;

  var sessionQuestions = [];
  var currentIndex = 0;
  var triviaScore = 0;
  var correctCount = 0;
  var winStreak = 0;
  var timerRafId = null;
  var timerStartedAt = 0;
  var answered = false;

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
    }
    return a;
  }

  function pickSessionQuestions() {
    var picked = shuffle(TRIVIA_QUESTIONS).slice(0, QUESTIONS_PER_SESSION);
    return picked.map(function (item) {
      var order = shuffle(item.options.map(function (_, i) { return i; }));
      var shuffledOptions = order.map(function (originalIdx) { return item.options[originalIdx]; });
      var newCorrectIndex = order.indexOf(item.correctIndex);
      return { q: item.q, options: shuffledOptions, correctIndex: newCorrectIndex };
    });
  }

  // Same streak-multiplier tiers as slot-bola.js (5 Sep 2026 v2) — kept
  // consistent site-wide now that it's an established "combo streak"
  // pattern, rather than inventing a different curve here.
  function streakMultiplier(streak) {
    if (streak >= 4) { return 2; }
    if (streak === 3) { return 1.5; }
    if (streak === 2) { return 1.2; }
    return 1;
  }

  function updateStreakBadge() {
    if (winStreak <= 0) {
      triviaStreakBadge.hidden = true;
      return;
    }
    triviaStreakValueEl.textContent = 'Streak x' + winStreak;
    triviaStreakBadge.hidden = false;
  }

  triviaStartBtn.addEventListener('click', startTriviaSession);
  triviaPlayAgainBtn.addEventListener('click', function () {
    triviaEndPanel.hidden = true;
    triviaStartPanel.hidden = false;
  });

  function startTriviaSession() {
    initAudio();
    sessionQuestions = pickSessionQuestions();
    currentIndex = 0;
    triviaScore = 0;
    correctCount = 0;
    winStreak = 0;
    triviaScoreEl.textContent = '0';
    updateStreakBadge();
    triviaStartPanel.hidden = true;
    triviaEndPanel.hidden = true;
    triviaBoard.hidden = false;
    showTriviaQuestion();
  }

  function showTriviaQuestion() {
    answered = false;
    var item = sessionQuestions[currentIndex];
    triviaQuestionCountEl.textContent = (currentIndex + 1) + '/' + QUESTIONS_PER_SESSION;
    triviaQuestionTextEl.textContent = item.q;
    triviaOptionsEl.innerHTML = '';
    item.options.forEach(function (label, idx) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wpm-pt-trivia-option-btn';
      btn.textContent = label;
      btn.addEventListener('click', function () { handleTriviaAnswer(idx); });
      triviaOptionsEl.appendChild(btn);
    });
    startTriviaTimer();
  }

  function startTriviaTimer() {
    stopTriviaTimer();
    triviaTimerFillEl.classList.remove('is-urgent');
    triviaTimerFillEl.style.transition = 'none';
    triviaTimerFillEl.style.width = '100%';
    timerStartedAt = performance.now();
    requestAnimationFrame(function () {
      triviaTimerFillEl.style.transition = 'width linear ' + (TIMER_MS / 1000) + 's, background-color 0.2s ease';
      triviaTimerFillEl.style.width = '0%';
    });

    function poll() {
      var elapsed = performance.now() - timerStartedAt;
      var remaining = TIMER_MS - elapsed;
      if (remaining <= TIMER_MS * 0.25) { triviaTimerFillEl.classList.add('is-urgent'); }
      if (remaining <= 0) { handleTriviaTimeout(); return; }
      timerRafId = requestAnimationFrame(poll);
    }
    timerRafId = requestAnimationFrame(poll);
  }
  function stopTriviaTimer() {
    if (timerRafId) { cancelAnimationFrame(timerRafId); timerRafId = null; }
  }

  function handleTriviaAnswer(pickedIndex) {
    if (answered) { return; }
    answered = true;
    stopTriviaTimer();
    var item = sessionQuestions[currentIndex];
    var isCorrect = pickedIndex === item.correctIndex;
    var optionButtons = triviaOptionsEl.querySelectorAll('.wpm-pt-trivia-option-btn');
    optionButtons.forEach(function (btn, idx) {
      btn.disabled = true;
      if (idx === item.correctIndex) { btn.classList.add('is-correct'); }
      else if (idx === pickedIndex) { btn.classList.add('is-wrong'); }
    });

    if (isCorrect) {
      winStreak++;
      var mult = streakMultiplier(winStreak);
      var points = Math.round(BASE_POINTS * mult);
      triviaScore += points;
      correctCount++;
      triviaScoreEl.textContent = String(triviaScore);
      sfx.correct();
    } else {
      winStreak = 0;
      sfx.wrong();
    }
    updateStreakBadge();
    setTimeout(goToNextTriviaQuestion, 700);
  }

  function handleTriviaTimeout() {
    if (answered) { return; }
    answered = true;
    winStreak = 0;
    updateStreakBadge();
    var item = sessionQuestions[currentIndex];
    var optionButtons = triviaOptionsEl.querySelectorAll('.wpm-pt-trivia-option-btn');
    optionButtons.forEach(function (btn, idx) {
      btn.disabled = true;
      if (idx === item.correctIndex) { btn.classList.add('is-correct'); }
    });
    sfx.timeout();
    setTimeout(goToNextTriviaQuestion, 700);
  }

  function goToNextTriviaQuestion() {
    currentIndex++;
    if (currentIndex >= sessionQuestions.length) {
      endTriviaSession();
      return;
    }
    showTriviaQuestion();
  }

  function endTriviaSession() {
    stopTriviaTimer();
    triviaBoard.hidden = true;
    triviaEndPanel.hidden = false;
    triviaEndScoreEl.textContent = String(triviaScore) + ' poin';
    triviaEndBreakdownEl.textContent = correctCount + ' dari ' + QUESTIONS_PER_SESSION + ' jawaban benar';
    sfx.finish();

    if (triviaScore > 0) {
      fetch('api/game-trivia-score.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ browser_id: getBrowserId(), nickname: nickname, points_earned: triviaScore }),
      }).catch(function () { /* best-effort — session score just won't reach the leaderboard this time */ });
    }
  }

  // ==================================================================
  // Leaderboard
  // ==================================================================
  var leaderboardLoaded = false;
  leaderboardToggle.addEventListener('click', function () {
    var willShow = leaderboardEl.hidden;
    leaderboardEl.hidden = !willShow;
    leaderboardToggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    leaderboardToggle.textContent = willShow ? 'Sembunyikan Leaderboard' : '🏆 Lihat Leaderboard';
    if (willShow && !leaderboardLoaded) { loadLeaderboard(); }
  });

  function loadLeaderboard() {
    fetch('api/game-leaderboard.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        leaderboardLoadingEl.hidden = true;
        if (!data.success || !Array.isArray(data.leaderboard) || data.leaderboard.length === 0) {
          leaderboardListEl.innerHTML = '<li class="wpm-pt-leaderboard__empty">Belum ada data — jadilah yang pertama!</li>';
          leaderboardLoaded = true;
          return;
        }
        leaderboardListEl.innerHTML = '';
        data.leaderboard.forEach(function (row) {
          var li = document.createElement('li');
          li.className = 'wpm-pt-leaderboard__row';
          // 12 Sep 2026 — link to that player's public history page
          // (games/prediksi-trivia/user.php?code=...) when the API gives
          // us a public_code. Falls back to a plain <span> (no link) for
          // any older row that somehow lacks one — public_code is
          // backfilled automatically for every player going forward (see
          // wpm_games_upsert_player() in includes/GamesShared.php), so
          // this fallback should be rare/transient in practice.
          var nameEl = row.public_code
            ? document.createElement('a')
            : document.createElement('span');
          nameEl.className = 'wpm-pt-leaderboard__name';
          nameEl.textContent = row.nickname;
          if (row.public_code) {
            nameEl.href = 'games/prediksi-trivia/user.php?code=' + encodeURIComponent(row.public_code);
          }
          var pointsEl = document.createElement('span');
          pointsEl.className = 'wpm-pt-leaderboard__points';
          pointsEl.textContent = row.total_points + ' poin';
          li.appendChild(nameEl);
          li.appendChild(pointsEl);
          leaderboardListEl.appendChild(li);
        });
        leaderboardLoaded = true;
      })
      .catch(function () {
        leaderboardLoadingEl.textContent = 'Gagal memuat leaderboard.';
      });
  }

  // ==================================================================
  // Boot
  // ==================================================================
  if (nickname) {
    showMain();
  } else {
    showNicknameGate();
  }
})();
