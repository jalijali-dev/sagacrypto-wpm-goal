/**
 * Sagagoal Games — Keepie-Uppie (8 Sep 2026, Games Hub game #6).
 *
 * Vanilla JS + Canvas 2D, zero dependencies, zero game engine — same
 * stack decision as air-hockey.js/penalty-kick.js (see docs/DECISIONS.md
 * for the original reasoning; not repeated here). 100% client-side —
 * NO backend, NO database, NO network request of any kind. This is
 * back to the "normal" Games Hub pattern; game #5 (Prediksi & Trivia)
 * is the one deliberate exception that needs a server, documented on
 * its own page/docs entry — this file has nothing to do with that.
 *
 * *** TEAMS array is a COPY of penalty-kick.js's, not a shared import ***
 * The brief offered 2 options: extract TEAMS into a shared
 * assets/games/js/teams-data.js loaded by both pages, or duplicate the
 * array here untouched. Chose duplication (Option B) — penalty-kick.js
 * is already shipped/tested in production, and the brief itself framed
 * extraction as the riskier option ("ubah file yang sudah jalan di
 * production... kalau devs menilai risikonya nggak sepadan, boleh pakai
 * Opsi B"). This also matches the project's existing, repeatedly-stated
 * convention of duplicating small patterns across game files instead of
 * sharing modules (see penalty-kick.js's own top docblock re: the
 * "synth a tone"/"particle burst" duplication) — one more 42-entry array
 * copy is consistent with that philosophy, not a new exception to it.
 *
 * Gameplay: a ball falls under gravity toward the player's feet. Tap/
 * click ANYWHERE on the canvas (no positional accuracy needed, same
 * "click anywhere" control style as air-hockey.js/penalty-kick.js) — a
 * click only has an effect if the ball is currently within the "hit
 * window" band around foot height AND still falling (vy > 0); outside
 * that window a click is silently a no-op, never a penalty (whiffing
 * a click was deliberately NOT punished — only genuinely missing the
 * ball, i.e. letting it fall past the window untouched, ends the game.
 * See attemptTouch()'s own comment for the full reasoning). Difficulty
 * (gravity + hit-window width) ramps up every 10 touches. Consecutive
 * "Perfect" touches (very close to the exact optimal height) build a
 * score multiplier (x1 -> x2 -> x3); any non-perfect touch resets the
 * multiplier to x1 but still scores its base points — never punitive,
 * per the brief's explicit "jangan bikin punitif" note.
 */
(function () {
  'use strict';

  // ---- Logical coordinate space (canvas scales via CSS; every
  // coordinate below is in these units regardless of on-screen size).
  // PORTRAIT (unlike air-hockey/penalty-kick's landscape space) — a
  // juggling ball needs vertical fall room, not width. ----
  var W = 300;
  var H = 400;
  var BALL_R = 12;
  var FOOT_Y = 330; // the "sweet spot" height the ball should be tapped at

  var canvas = document.getElementById('ku-canvas');
  var ctx = canvas ? canvas.getContext('2d') : null;

  var panelStart = document.getElementById('ku-panel-start');
  var panelEnd = document.getElementById('ku-panel-end');
  var boardEl = document.getElementById('ku-board');
  var startBtn = document.getElementById('ku-start-btn');
  var restartBtn = document.getElementById('ku-restart-btn');
  var playAgainBtn = document.getElementById('ku-play-again-btn');
  var touchCountEl = document.getElementById('ku-touch-count');
  var highscoreLiveEl = document.getElementById('ku-highscore-live');
  var multiplierBadgeEl = document.getElementById('ku-multiplier-badge');
  var endTitleEl = document.getElementById('ku-end-title');
  var endScoreEl = document.getElementById('ku-end-score');
  var endHighscoreEl = document.getElementById('ku-end-highscore');
  var muteBtn = document.getElementById('ku-mute-btn');
  var panelTeam = document.getElementById('ku-panel-team');
  var teamGridEl = document.getElementById('ku-team-grid');
  var teamHintEl = document.getElementById('ku-team-hint');
  var changeTeamBtn = document.getElementById('ku-change-team-btn');
  var startHighscoreEl = document.getElementById('ku-start-highscore');

  if (!canvas || !ctx) { return; }

  // ==================================================================
  // High score — localStorage only, namespaced key so it never collides
  // with game #5's browser_id/nickname keys (that game uses `pt_*`,
  // this one is entirely unrelated and needs no identity at all).
  // ==================================================================
  var LS_HIGHSCORE = 'wpm_keepie_uppie_highscore';
  function getHighScore() {
    try { return parseInt(localStorage.getItem(LS_HIGHSCORE), 10) || 0; } catch (e) { return 0; }
  }
  function setHighScore(v) {
    try { localStorage.setItem(LS_HIGHSCORE, String(v)); } catch (e) { /* ignore — high score just won't persist this session */ }
  }
  var highScore = getHighScore();
  if (highScore > 0) {
    highscoreLiveEl.textContent = String(highScore);
    startHighscoreEl.textContent = 'Rekor kamu: ' + highScore + ' poin';
    startHighscoreEl.hidden = false;
  }

  // ==================================================================
  // Team select — purely cosmetic (jersey color), see file docblock for
  // why this array is a duplicate of penalty-kick.js's, not shared.
  // ==================================================================
  var TEAMS = [
    { code: 'BR', name: 'Brasil', flag: '🇧🇷', color: '#ffdf00' },
    { code: 'AR', name: 'Argentina', flag: '🇦🇷', color: '#75aadb' },
    { code: 'DE', name: 'Jerman', flag: '🇩🇪', color: '#e30613' },
    { code: 'FR', name: 'Prancis', flag: '🇫🇷', color: '#0055a4' },
    { code: 'IT', name: 'Italia', flag: '🇮🇹', color: '#0066cc' },
    { code: 'ES', name: 'Spanyol', flag: '🇪🇸', color: '#c60b1e' },
    { code: 'GB', name: 'Inggris', flag: '🏴󠁧󠁢󠁥󠁮󠁧󠁿', color: '#c8102e' },
    { code: 'NL', name: 'Belanda', flag: '🇳🇱', color: '#ff6600' },
    { code: 'PT', name: 'Portugal', flag: '🇵🇹', color: '#b30000' },
    { code: 'BE', name: 'Belgia', flag: '🇧🇪', color: '#ed2939' },
    { code: 'HR', name: 'Kroasia', flag: '🇭🇷', color: '#e2001a' },
    { code: 'UY', name: 'Uruguay', flag: '🇺🇾', color: '#5ab6e8' },
    { code: 'MX', name: 'Meksiko', flag: '🇲🇽', color: '#006341' },
    { code: 'US', name: 'Amerika Serikat', flag: '🇺🇸', color: '#3c3b6e' },
    { code: 'JP', name: 'Jepang', flag: '🇯🇵', color: '#002171' },
    { code: 'KR', name: 'Korea Selatan', flag: '🇰🇷', color: '#c60c30' },
    { code: 'MA', name: 'Maroko', flag: '🇲🇦', color: '#c1272d' },
    { code: 'SN', name: 'Senegal', flag: '🇸🇳', color: '#00853f' },
    { code: 'GH', name: 'Ghana', flag: '🇬🇭', color: '#ce1126' },
    { code: 'NG', name: 'Nigeria', flag: '🇳🇬', color: '#008751' },
    { code: 'CM', name: 'Kamerun', flag: '🇨🇲', color: '#ce1126' },
    { code: 'TN', name: 'Tunisia', flag: '🇹🇳', color: '#e70013' },
    { code: 'EG', name: 'Mesir', flag: '🇪🇬', color: '#ce1126' },
    { code: 'SA', name: 'Arab Saudi', flag: '🇸🇦', color: '#006c35' },
    { code: 'QA', name: 'Qatar', flag: '🇶🇦', color: '#8d1b3d' },
    { code: 'IR', name: 'Iran', flag: '🇮🇷', color: '#da0000' },
    { code: 'AU', name: 'Australia', flag: '🇦🇺', color: '#00843d' },
    { code: 'CA', name: 'Kanada', flag: '🇨🇦', color: '#ff0000' },
    { code: 'CH', name: 'Swiss', flag: '🇨🇭', color: '#ff0000' },
    { code: 'PL', name: 'Polandia', flag: '🇵🇱', color: '#dc143c' },
    { code: 'DK', name: 'Denmark', flag: '🇩🇰', color: '#c8102e' },
    { code: 'SE', name: 'Swedia', flag: '🇸🇪', color: '#fecc02' },
    { code: 'RS', name: 'Serbia', flag: '🇷🇸', color: '#c6363c' },
    { code: 'EC', name: 'Ekuador', flag: '🇪🇨', color: '#ffce00' },
    { code: 'CR', name: 'Kosta Rika', flag: '🇨🇷', color: '#ce1126' },
    { code: 'CI', name: 'Pantai Gading', flag: '🇨🇮', color: '#ff8200' },
    { code: 'CO', name: 'Kolombia', flag: '🇨🇴', color: '#fcd116' },
    { code: 'CL', name: 'Chili', flag: '🇨🇱', color: '#d52b1e' },
    { code: 'PE', name: 'Peru', flag: '🇵🇪', color: '#d91023' },
    { code: 'PY', name: 'Paraguay', flag: '🇵🇾', color: '#0038a8' },
    { code: 'DZ', name: 'Aljazair', flag: '🇩🇿', color: '#006233' },
    { code: 'ZA', name: 'Afrika Selatan', flag: '🇿🇦', color: '#007749' },
  ];

  var selectedTeam = null;
  function playerColor() { return (selectedTeam && selectedTeam.color) || '#4d7cff'; }

  function renderTeamGrid() {
    if (!teamGridEl) { return; }
    TEAMS.forEach(function (team) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wpm-ku-team-btn';
      btn.setAttribute('data-code', team.code);

      var flagEl = document.createElement('span');
      flagEl.className = 'wpm-ku-team-btn__flag';
      flagEl.setAttribute('aria-hidden', 'true');
      flagEl.textContent = team.flag;

      var nameEl = document.createElement('span');
      nameEl.textContent = team.name;

      btn.appendChild(flagEl);
      btn.appendChild(nameEl);
      btn.addEventListener('click', function () { selectTeam(team, btn); });
      teamGridEl.appendChild(btn);
    });
  }

  function selectTeam(team, btnEl) {
    selectedTeam = team;
    if (teamGridEl) {
      teamGridEl.querySelectorAll('.wpm-ku-team-btn').forEach(function (b) { b.classList.remove('is-selected'); });
    }
    if (btnEl) { btnEl.classList.add('is-selected'); }
    if (teamHintEl) {
      teamHintEl.textContent = 'Main sebagai ' + team.flag + ' ' + team.name + '. Tap/klik dengan timing pas buat mantulin bola — jangan sampai jatuh!';
    }
    if (panelTeam) { panelTeam.hidden = true; }
    if (panelStart) { panelStart.hidden = false; }
  }

  renderTeamGrid();

  if (changeTeamBtn) {
    changeTeamBtn.addEventListener('click', function () {
      if (panelStart) { panelStart.hidden = true; }
      if (panelTeam) { panelTeam.hidden = false; }
    });
  }

  // ==================================================================
  // Audio — synthesized via Web Audio API, same envelope-tone technique
  // as air-hockey.js/penalty-kick.js. Duplicated here rather than
  // shared, per this file's top docblock. ----
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
    touch: function () { playTone([260, 340], 0.08, 'square', 0.07); },
    perfect: function () {
      playTone(659.25, 0.08, 'triangle', 0.1, 0);
      playTone(987.77, 0.12, 'triangle', 0.11, 0.06);
    },
    star: function () { playTone([784, 1046.5], 0.14, 'triangle', 0.1, 0); },
    milestone: function () {
      playTone(523.25, 0.1, 'triangle', 0.1, 0);
      playTone(783.99, 0.16, 'triangle', 0.11, 0.09);
    },
    gameOver: function () {
      playTone(392.0, 0.16, 'sawtooth', 0.08, 0);
      playTone(329.63, 0.16, 'sawtooth', 0.08, 0.14);
      playTone(220.0, 0.28, 'sawtooth', 0.08, 0.28);
    },
    newRecord: function () {
      playTone(523.25, 0.12, 'triangle', 0.12, 0);
      playTone(659.25, 0.12, 'triangle', 0.12, 0.11);
      playTone(783.99, 0.12, 'triangle', 0.12, 0.22);
      playTone(1046.5, 0.26, 'triangle', 0.13, 0.33);
    },
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

  // ==================================================================
  // Particle burst — same small hand-rolled system as penalty-kick.js's,
  // duplicated per this file's top docblock.
  // ==================================================================
  var particles = [];
  function spawnBurst(x, y, rgbTriplet, count) {
    for (var i = 0; i < count; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 1 + Math.random() * 2.5;
      particles.push({
        x: x, y: y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        life: 1,
        decay: 0.035 + Math.random() * 0.03,
        r: 1.5 + Math.random() * 2,
        color: 'rgb(' + rgbTriplet + ')',
      });
    }
  }
  function updateParticles() {
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      p.x += p.vx; p.y += p.vy;
      p.vx *= 0.95; p.vy *= 0.95;
      p.life -= p.decay;
      if (p.life <= 0) { particles.splice(i, 1); }
    }
  }
  function drawParticles() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      ctx.globalAlpha = Math.max(0, p.life);
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = p.color;
      ctx.fill();
    }
    ctx.globalAlpha = 1;
  }

  // ==================================================================
  // Difficulty ramp — every 10 touches, gravity goes up a bit and the
  // hit/perfect windows narrow a bit (floored so it never becomes
  // unfairly impossible). Recomputed after every successful touch.
  // ==================================================================
  var BASE_GRAVITY = 0.28;
  var BASE_HIT_WINDOW = 34;
  var BASE_PERFECT_WINDOW = 12;
  var KICK_APEX = 150; // desired rise height (px, logical space) after a hit

  function applyDifficulty(touches) {
    var tier = Math.floor(touches / 10);
    state.gravity = BASE_GRAVITY * Math.pow(1.09, tier);
    state.hitWindow = Math.max(16, BASE_HIT_WINDOW - tier * 2);
    state.perfectWindow = Math.max(6, BASE_PERFECT_WINDOW - tier * 0.8);
  }

  // Multiplier tiers, same "x1/x2/x3, capped" shape as the streak
  // multipliers already established elsewhere in Games Hub (slot-bola.js
  // v2, prediksi-trivia.js) — kept consistent site-wide rather than
  // inventing a 4th curve.
  function multiplierFor(perfectStreak) {
    if (perfectStreak >= 3) { return 3; }
    if (perfectStreak === 2) { return 2; }
    return 1;
  }

  var BASE_TOUCH_POINTS = 10;
  var STAR_BONUS_POINTS = 5;
  var STAR_SPAWN_CHANCE = 0.18;

  var running = false;
  var rafId = null;
  var state = null;

  function resetGame() {
    state = {
      ball: { y: H * 0.28, vy: 0 },
      touches: 0,
      score: 0,
      perfectStreak: 0,
      multiplier: 1,
      starActive: false,
      starPulse: 0,
      gravity: BASE_GRAVITY,
      hitWindow: BASE_HIT_WINDOW,
      perfectWindow: BASE_PERFECT_WINDOW,
      kickFlickFrames: 0, // purely cosmetic leg-flick animation timer, see drawPlayer()
      lastTouchWasPerfect: false,
    };
  }

  function showStart() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    panelStart.hidden = false;
    boardEl.hidden = true;
    panelEnd.hidden = true;
  }

  function startGame() {
    initAudio();
    resetGame();
    panelStart.hidden = true;
    panelEnd.hidden = true;
    boardEl.hidden = false;
    updateScoreboard();
    running = true;
    if (rafId) { cancelAnimationFrame(rafId); }
    rafId = requestAnimationFrame(loop);
  }

  function updateScoreboard() {
    if (!state) { return; }
    touchCountEl.textContent = String(state.touches);
    highscoreLiveEl.textContent = String(Math.max(highScore, state.score));
    if (state.multiplier > 1) {
      multiplierBadgeEl.textContent = 'x' + state.multiplier;
      multiplierBadgeEl.hidden = false;
    } else {
      multiplierBadgeEl.hidden = true;
    }
  }

  startBtn.addEventListener('click', startGame);
  restartBtn.addEventListener('click', showStart);
  playAgainBtn.addEventListener('click', startGame);

  // ==================================================================
  // Input — click anywhere on the canvas (desktop) or tap (mobile), NO
  // debounce/throttle of any kind (brief: "delay input SEDIKIT PUN
  // langsung terasa buruk" — this is a reaction game). touchend's
  // preventDefault() stops the browser from also firing a synthetic
  // click right after, same pattern as penalty-kick.js's touchend
  // listener.
  // ==================================================================
  canvas.addEventListener('click', function () { attemptTouch(); });
  canvas.addEventListener('touchstart', function (e) {
    attemptTouch();
    e.preventDefault();
  }, { passive: false });

  /**
   * A click/tap is ALWAYS accepted as input, but only ever has an
   * effect when the ball is currently inside the hit window AND still
   * falling (vy > 0 — this also naturally prevents a rapid double-click
   * from double-scoring the same fall, since the first click already
   * flips vy negative before a second click can be processed). Clicking
   * too early/too late/while the ball is mid-rise is a silent no-op —
   * deliberately NOT a penalty, same "don't punish an off-timing input"
   * spirit the brief asked for around the multiplier reset. The ONLY
   * way to lose is passive: letting the ball's own gravity carry it
   * past the hit window untouched.
   */
  function attemptTouch() {
    if (!running || !state) { return; }
    var dist = Math.abs(state.ball.y - FOOT_Y);
    if (state.ball.vy <= 0 || dist > state.hitWindow) { return; } // not currently hittable — no-op, no penalty

    var isPerfect = dist <= state.perfectWindow;
    state.perfectStreak = isPerfect ? state.perfectStreak + 1 : 0;
    state.multiplier = multiplierFor(state.perfectStreak);
    state.lastTouchWasPerfect = isPerfect;

    var gained = BASE_TOUCH_POINTS * state.multiplier;
    if (state.starActive) {
      gained += STAR_BONUS_POINTS;
      state.starActive = false;
      sfx.star();
      spawnBurst(W / 2, state.ball.y, '255,210,63', 10);
    }
    state.score += gained;
    state.touches++;
    applyDifficulty(state.touches);

    // Kick the ball back up — apex height derived from the CURRENT
    // gravity tier (not a fixed velocity) so the rise still feels
    // consistent even as gravity ramps up with difficulty. Small random
    // factor keeps every bounce feeling slightly alive, not robotic.
    var apexFactor = 0.9 + Math.random() * 0.2;
    state.ball.vy = -Math.sqrt(2 * state.gravity * KICK_APEX * apexFactor);

    state.kickFlickFrames = 10;

    if (isPerfect) {
      sfx.perfect();
      spawnBurst(W / 2, state.ball.y, '77,124,255', 12);
    } else {
      sfx.touch();
    }
    if (state.touches > 0 && state.touches % 10 === 0) { sfx.milestone(); }

    // Roll whether a bonus star will be active during the NEXT descent
    // — purely decorative/bonus, never affects gravity/hit-window/
    // game-over logic (per brief).
    state.starActive = Math.random() < STAR_SPAWN_CHANCE;

    updateScoreboard();
  }

  // ==================================================================
  // Game loop
  // ==================================================================
  function endGame() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    var isNewRecord = state.score > highScore;
    if (isNewRecord) {
      highScore = state.score;
      setHighScore(highScore);
    }
    endTitleEl.textContent = isNewRecord ? 'Rekor Baru! 🏆' : 'Bola Jatuh!';
    // `score` (poin, multiplier-weighted) is the tracked/compared metric
    // — `touches` (raw count) is shown alongside for context but is
    // NEVER what high-score comparisons use, otherwise the whole Perfect/
    // multiplier system would have no effect on what actually gets
    // recorded (see attemptTouch()'s docblock).
    endScoreEl.textContent = String(state.score) + ' poin';
    var touchLine = state.touches + ' sentuhan';
    endHighscoreEl.textContent = isNewRecord
      ? touchLine + ' — kamu memecahkan rekor sebelumnya!'
      : touchLine + ' • Rekor tertinggi: ' + highScore + ' poin';
    panelEnd.hidden = false;
    if (isNewRecord) { sfx.newRecord(); } else { sfx.gameOver(); }
  }

  function loop() {
    if (!running || !state) { return; }

    state.ball.vy += state.gravity;
    state.ball.y += state.ball.vy;

    // Miss condition: the ball fell PAST the hit window while still
    // falling, untouched. This is the only way to lose — see
    // attemptTouch()'s docblock for why whiffed clicks are never
    // punished on their own.
    if (state.ball.vy > 0 && (state.ball.y - FOOT_Y) > state.hitWindow) {
      updateParticles();
      draw();
      endGame();
      return;
    }

    if (state.kickFlickFrames > 0) { state.kickFlickFrames--; }

    updateParticles();
    draw();
    rafId = requestAnimationFrame(loop);
  }

  // ==================================================================
  // Rendering
  // ==================================================================
  function setupCanvasResolution() {
    var dpr = window.devicePixelRatio || 1;
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  setupCanvasResolution();
  window.addEventListener('resize', setupCanvasResolution);

  var pitchGradient = null;
  function getPitchGradient() {
    if (pitchGradient) { return pitchGradient; }
    var g = ctx.createRadialGradient(W / 2, H * 0.85, 30, W / 2, H * 0.85, H);
    g.addColorStop(0, '#14502a');
    g.addColorStop(0.6, '#0b3d1f');
    g.addColorStop(1, '#062712');
    pitchGradient = g;
    return g;
  }

  /**
   * Darkens (negative percent) or lightens (positive) a 6-digit hex
   * color — same helper as penalty-kick.js's shadeColor(), copied for
   * the same "derive shorts/socks shade from the jersey color" purpose
   * (see file docblock re: duplication over a shared module).
   */
  function shadeColor(hex, percent) {
    var num = parseInt(hex.replace('#', ''), 16);
    var amt = Math.round(2.55 * percent);
    var r = Math.max(0, Math.min(255, (num >> 16) + amt));
    var g = Math.max(0, Math.min(255, ((num >> 8) & 0x00ff) + amt));
    var b = Math.max(0, Math.min(255, (num & 0x0000ff) + amt));
    return '#' + (0x1000000 + r * 0x10000 + g * 0x100 + b).toString(16).slice(1);
  }

  /**
   * Player — standing at fixed (W/2, FOOT_Y), idle stance with a brief
   * cosmetic "leg flick" animation (state.kickFlickFrames, purely a
   * render-timing countdown set by attemptTouch()) after a successful
   * touch. Body-piece layering (socks+shoes -> shorts -> jersey -> arms
   * -> head) is the same "papercraft kit" structure as penalty-kick.js's
   * drawKicker()/drawKeeper() (jersey/shorts/socks/shoes/gradient head),
   * reused for visual consistency across the Games Hub product family —
   * simplified here (no wind-up/strike phases, this game has no ball-
   * aiming direction to animate toward).
   */
  function drawPlayer(color) {
    var hipX = W / 2;
    var hipY = FOOT_Y + 26;
    var torsoLen = 15;
    var legLen = 15;
    var headR = 5;

    // state is null during the idle preview draw() call before a game has
    // started (see draw()'s own `if (!state)` branch) — flickT just stays
    // 0 (idle stance) in that case rather than throwing.
    var flickT = (state ? state.kickFlickFrames : 0) / 10; // 1 -> 0 over the flick
    var legSwing = -0.15 - flickT * 0.55; // idle stance, kicks back briefly on touch

    var shoulderX = hipX;
    var shoulderY = hipY - torsoLen;
    var headX = shoulderX;
    var headY = shoulderY - headR - 2;

    // Ground shadow.
    ctx.beginPath();
    ctx.ellipse(hipX, hipY + legLen + 2, 18, 5, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,0.38)';
    ctx.fill();

    // Glow halo.
    ctx.beginPath();
    ctx.arc(hipX, hipY - torsoLen / 2, 18, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.14;
    ctx.shadowColor = color;
    ctx.shadowBlur = 16;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;

    var socksColor = shadeColor(color, -40);
    var shortsColor = shadeColor(color, -28);
    var shoeColor = '#161616';

    var supportFootX = hipX - 5;
    var supportFootY = hipY + legLen;
    var footX = hipX + Math.sin(legSwing) * legLen;
    var footY = hipY + Math.cos(legSwing) * legLen;

    ctx.strokeStyle = socksColor;
    ctx.lineWidth = 5;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 6;
    ctx.beginPath();
    ctx.moveTo(hipX, hipY); ctx.lineTo(supportFootX, supportFootY);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(hipX, hipY); ctx.lineTo(footX, footY);
    ctx.stroke();
    ctx.shadowBlur = 0;
    ctx.fillStyle = shoeColor;
    ctx.beginPath(); ctx.ellipse(supportFootX, supportFootY + 1, 4.4, 2.6, -0.15, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(footX, footY, 4.4, 2.6, legSwing, 0, Math.PI * 2); ctx.fill();

    ctx.shadowColor = color;
    ctx.shadowBlur = 4;
    ctx.fillStyle = shortsColor;
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(hipX - 7, hipY - 5, 14, 9, 3);
    } else {
      ctx.rect(hipX - 7, hipY - 5, 14, 9);
    }
    ctx.fill();
    ctx.shadowBlur = 0;

    var torsoGrad = ctx.createLinearGradient(hipX - 7, shoulderY, hipX + 7, hipY);
    torsoGrad.addColorStop(0, color);
    torsoGrad.addColorStop(0.5, '#ffffff');
    torsoGrad.addColorStop(1, color);
    ctx.globalAlpha = 0.9;
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(hipX - 7, shoulderY, 14, torsoLen, 5);
    } else {
      ctx.rect(hipX - 7, shoulderY, 14, torsoLen);
    }
    ctx.fillStyle = torsoGrad;
    ctx.fill();
    ctx.globalAlpha = 1;

    ctx.strokeStyle = color;
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 8;
    // Arms, slightly out for balance.
    ctx.beginPath();
    ctx.moveTo(shoulderX, shoulderY + 2); ctx.lineTo(shoulderX - 10, shoulderY + 8);
    ctx.moveTo(shoulderX, shoulderY + 2); ctx.lineTo(shoulderX + 10, shoulderY + 8);
    ctx.stroke();
    ctx.shadowBlur = 0;

    var headGrad = ctx.createRadialGradient(headX - headR * 0.3, headY - headR * 0.3, headR * 0.2, headX, headY, headR);
    headGrad.addColorStop(0, '#fff3c4');
    headGrad.addColorStop(1, color);
    ctx.beginPath();
    ctx.arc(headX, headY, headR, 0, Math.PI * 2);
    ctx.fillStyle = headGrad;
    ctx.fill();
  }

  /** Soccer ball — same pentagon-seam sphere-shading technique as penalty-kick.js's drawBall(), duplicated (see file docblock). */
  function drawBallPattern(x, y, r) {
    var sides = 5;
    var pR = r * 0.5;
    ctx.beginPath();
    for (var i = 0; i <= sides; i++) {
      var angle = -Math.PI / 2 + i * (Math.PI * 2 / sides);
      var px = x + Math.cos(angle) * pR;
      var py = y + Math.sin(angle) * pR;
      if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
    }
    ctx.closePath();
    ctx.fillStyle = '#1a1a1a';
    ctx.fill();
    ctx.strokeStyle = 'rgba(20,20,20,0.55)';
    ctx.lineWidth = Math.max(0.8, r * 0.09);
    for (var j = 0; j < sides; j++) {
      var a2 = -Math.PI / 2 + j * (Math.PI * 2 / sides);
      var innerX = x + Math.cos(a2) * pR;
      var innerY = y + Math.sin(a2) * pR;
      var outerX = x + Math.cos(a2) * r * 0.92;
      var outerY = y + Math.sin(a2) * r * 0.92;
      ctx.beginPath();
      ctx.moveTo(innerX, innerY);
      ctx.lineTo(outerX, outerY);
      ctx.stroke();
    }
  }

  function drawBall(x, y) {
    ctx.beginPath();
    ctx.ellipse(x, Math.min(H - 4, FOOT_Y + 34), BALL_R * 1.1, BALL_R * 0.35, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,0.22)';
    ctx.fill();

    ctx.beginPath();
    ctx.arc(x, y, BALL_R * 1.5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,176,59,0.2)';
    ctx.shadowColor = 'rgba(255,176,59,0.7)';
    ctx.shadowBlur = 10;
    ctx.fill();
    ctx.shadowBlur = 0;

    var grad = ctx.createRadialGradient(x - BALL_R * 0.35, y - BALL_R * 0.35, BALL_R * 0.15, x, y, BALL_R);
    grad.addColorStop(0, '#ffffff');
    grad.addColorStop(0.65, '#eef1f5');
    grad.addColorStop(1, '#b9c2cc');
    ctx.beginPath();
    ctx.arc(x, y, BALL_R, 0, Math.PI * 2);
    ctx.fillStyle = grad;
    ctx.fill();
    drawBallPattern(x, y, BALL_R);
    ctx.strokeStyle = 'rgba(0,0,0,0.25)';
    ctx.lineWidth = Math.max(0.6, BALL_R * 0.06);
    ctx.beginPath();
    ctx.arc(x, y, BALL_R, 0, Math.PI * 2);
    ctx.stroke();
  }

  /** 5-point star — bonus item marker, drawn near the ball when state.starActive. */
  function drawStar(x, y, r, color) {
    ctx.beginPath();
    for (var i = 0; i < 10; i++) {
      var angle = -Math.PI / 2 + i * (Math.PI / 5);
      var rad = (i % 2 === 0) ? r : r * 0.45;
      var px = x + Math.cos(angle) * rad;
      var py = y + Math.sin(angle) * rad;
      if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
    }
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.shadowColor = color;
    ctx.shadowBlur = 10;
    ctx.fill();
    ctx.shadowBlur = 0;
  }

  function draw() {
    ctx.fillStyle = getPitchGradient();
    ctx.fillRect(0, 0, W, H);

    // Faint mow-stripe bands, same texture technique as the other games.
    ctx.fillStyle = 'rgba(255,255,255,0.02)';
    for (var stripe = 0; stripe < H; stripe += 30) {
      if ((stripe / 30) % 2 === 0) { ctx.fillRect(0, stripe, W, 30); }
    }

    if (!state) {
      drawPlayer(playerColor());
      drawBall(W / 2, H * 0.28);
      return;
    }

    // Hit-window indicator — a thin glow band at FOOT_Y so the player
    // has a visible target height, brighter when the ball is currently
    // inside it (a soft "you can tap now" cue, purely visual — the
    // actual hit-test in attemptTouch() doesn't read this at all).
    var ballInWindow = state.ball.vy > 0 && Math.abs(state.ball.y - FOOT_Y) <= state.hitWindow;
    ctx.fillStyle = ballInWindow ? 'rgba(77,124,255,0.16)' : 'rgba(77,124,255,0.07)';
    ctx.fillRect(0, FOOT_Y - state.hitWindow, W, state.hitWindow * 2);

    drawPlayer(playerColor());

    if (state.starActive) {
      drawStar(W / 2 + 22, state.ball.y - 18, 8, '#ffd23f');
    }
    drawBall(W / 2, state.ball.y);
    drawParticles();
  }

  // Idle preview before a game starts.
  draw();
})();
