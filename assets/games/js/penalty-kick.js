/**
 * Sagagoal Games — Penalty Kick (2 Sep 2026, Games Hub game #2).
 *
 * Vanilla JS + Canvas 2D, zero dependencies, zero game engine — same
 * stack decision as air-hockey.js (see docs/DECISIONS.md, 30 Agu 2026
 * entry, for the original reasoning; this file doesn't repeat it).
 * Deliberately does NOT import/require air-hockey.js — the brief asked
 * for fully separate files per game, so the small "synth a tone" /
 * "particle burst" patterns below are duplicated in spirit, not shared
 * via a common module (see docs/DECISIONS.md, 2 Sep 2026 entry, for why
 * that duplication was the pragmatic call here over extracting a shared
 * helper file for just 2 games).
 *
 * Gameplay: player takes 5 penalty kicks by clicking/tapping one of 5
 * zones in the goal (top-left/top-right/center/bottom-left/bottom-right).
 * The AI keeper commits to a zone at the moment of the kick (same as a
 * real goalkeeper reading the run-up, not the flight) with a
 * difficulty-dependent chance of guessing the SAME zone the player
 * picked — if it matches, that's a save; otherwise it's a goal. No
 * physics beyond "does zone A equal zone B" — deliberately simple, per
 * the brief's own "hindari physics rumit" guidance.
 *
 * Score is in-memory only, resets on page reload — same as air-hockey.js
 * (no backend/leaderboard yet, see docs/DECISIONS.md, 30 Agu 2026).
 */
(function () {
  'use strict';

  // ---- Logical coordinate space (canvas scales via CSS; every
  // coordinate below is in these units regardless of on-screen size). ----
  var W = 400;
  var H = 300;
  var GOAL_LEFT = 60;
  var GOAL_RIGHT = 340;
  var GOAL_TOP = 40;
  var GOAL_BOTTOM = 200;
  var GOAL_W = GOAL_RIGHT - GOAL_LEFT;
  var GOAL_H = GOAL_BOTTOM - GOAL_TOP;
  var BALL_START_X = W / 2;
  var BALL_START_Y = 268;
  var BALL_R = 10;
  var TOTAL_SHOTS = 5;

  /** The 5 aimable zones — id, target point, and a small hit-test radius used to map a click to "nearest zone". */
  var ZONES = [
    { id: 'top-left', x: GOAL_LEFT + GOAL_W * 0.18, y: GOAL_TOP + GOAL_H * 0.28 },
    { id: 'top-right', x: GOAL_LEFT + GOAL_W * 0.82, y: GOAL_TOP + GOAL_H * 0.28 },
    { id: 'center', x: GOAL_LEFT + GOAL_W * 0.5, y: GOAL_TOP + GOAL_H * 0.58 },
    { id: 'bottom-left', x: GOAL_LEFT + GOAL_W * 0.18, y: GOAL_TOP + GOAL_H * 0.82 },
    { id: 'bottom-right', x: GOAL_LEFT + GOAL_W * 0.82, y: GOAL_TOP + GOAL_H * 0.82 },
  ];

  var canvas = document.getElementById('pk-canvas');
  var ctx = canvas ? canvas.getContext('2d') : null;

  var panelStart = document.getElementById('pk-panel-start');
  var panelEnd = document.getElementById('pk-panel-end');
  var boardEl = document.getElementById('pk-board');
  var startBtn = document.getElementById('pk-start-btn');
  var restartBtn = document.getElementById('pk-restart-btn');
  var playAgainBtn = document.getElementById('pk-play-again-btn');
  var difficultyBtns = document.querySelectorAll('.wpm-pk-difficulty__btn');
  var difficultyBadge = document.getElementById('pk-difficulty-badge');
  var shotCountEl = document.getElementById('pk-shot-count');
  var goalCountEl = document.getElementById('pk-goal-count');
  var endTitleEl = document.getElementById('pk-end-title');
  var endScoreEl = document.getElementById('pk-end-score');
  var muteBtn = document.getElementById('pk-mute-btn');
  var panelTeam = document.getElementById('pk-panel-team');
  var teamGridEl = document.getElementById('pk-team-grid');
  var teamHintEl = document.getElementById('pk-team-hint');
  var teamFlagBadgeEl = document.getElementById('pk-team-flag-badge');
  var changeTeamBtn = document.getElementById('pk-change-team-btn');

  if (!canvas || !ctx) { return; }

  // ---- Team select (2 Sep 2026, operator request) — purely cosmetic:
  // which flag shows next to "Gol" in the scoreboard and in the start
  // panel's hint text. Never read by any gameplay/scoring/AI code below
  // — pickKeeperZone(), the goal/save comparison, and DIFFICULTY_TUNING
  // don't know this exists. 42 real World Cup nations (not a themed
  // "pick your favorite" list with invented entries), flags rendered as
  // plain Unicode emoji (zero image assets, zero payload cost) — same
  // "no external asset" reasoning as the synthesized audio elsewhere in
  // this file, see docs/DECISIONS.md. ----
  var TEAMS = [
    { code: 'BR', name: 'Brasil', flag: '🇧🇷' },
    { code: 'AR', name: 'Argentina', flag: '🇦🇷' },
    { code: 'DE', name: 'Jerman', flag: '🇩🇪' },
    { code: 'FR', name: 'Prancis', flag: '🇫🇷' },
    { code: 'IT', name: 'Italia', flag: '🇮🇹' },
    { code: 'ES', name: 'Spanyol', flag: '🇪🇸' },
    { code: 'GB', name: 'Inggris', flag: '🏴󠁧󠁢󠁥󠁮󠁧󠁿' },
    { code: 'NL', name: 'Belanda', flag: '🇳🇱' },
    { code: 'PT', name: 'Portugal', flag: '🇵🇹' },
    { code: 'BE', name: 'Belgia', flag: '🇧🇪' },
    { code: 'HR', name: 'Kroasia', flag: '🇭🇷' },
    { code: 'UY', name: 'Uruguay', flag: '🇺🇾' },
    { code: 'MX', name: 'Meksiko', flag: '🇲🇽' },
    { code: 'US', name: 'Amerika Serikat', flag: '🇺🇸' },
    { code: 'JP', name: 'Jepang', flag: '🇯🇵' },
    { code: 'KR', name: 'Korea Selatan', flag: '🇰🇷' },
    { code: 'MA', name: 'Maroko', flag: '🇲🇦' },
    { code: 'SN', name: 'Senegal', flag: '🇸🇳' },
    { code: 'GH', name: 'Ghana', flag: '🇬🇭' },
    { code: 'NG', name: 'Nigeria', flag: '🇳🇬' },
    { code: 'CM', name: 'Kamerun', flag: '🇨🇲' },
    { code: 'TN', name: 'Tunisia', flag: '🇹🇳' },
    { code: 'EG', name: 'Mesir', flag: '🇪🇬' },
    { code: 'SA', name: 'Arab Saudi', flag: '🇸🇦' },
    { code: 'QA', name: 'Qatar', flag: '🇶🇦' },
    { code: 'IR', name: 'Iran', flag: '🇮🇷' },
    { code: 'AU', name: 'Australia', flag: '🇦🇺' },
    { code: 'CA', name: 'Kanada', flag: '🇨🇦' },
    { code: 'CH', name: 'Swiss', flag: '🇨🇭' },
    { code: 'PL', name: 'Polandia', flag: '🇵🇱' },
    { code: 'DK', name: 'Denmark', flag: '🇩🇰' },
    { code: 'SE', name: 'Swedia', flag: '🇸🇪' },
    { code: 'RS', name: 'Serbia', flag: '🇷🇸' },
    { code: 'EC', name: 'Ekuador', flag: '🇪🇨' },
    { code: 'CR', name: 'Kosta Rika', flag: '🇨🇷' },
    { code: 'CI', name: 'Pantai Gading', flag: '🇨🇮' },
    { code: 'CO', name: 'Kolombia', flag: '🇨🇴' },
    { code: 'CL', name: 'Chili', flag: '🇨🇱' },
    { code: 'PE', name: 'Peru', flag: '🇵🇪' },
    { code: 'PY', name: 'Paraguay', flag: '🇵🇾' },
    { code: 'DZ', name: 'Aljazair', flag: '🇩🇿' },
    { code: 'ZA', name: 'Afrika Selatan', flag: '🇿🇦' },
  ];

  var selectedTeam = null;

  function renderTeamGrid() {
    if (!teamGridEl) { return; }
    TEAMS.forEach(function (team) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wpm-pk-team-btn';
      btn.setAttribute('data-code', team.code);

      var flagEl = document.createElement('span');
      flagEl.className = 'wpm-pk-team-btn__flag';
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
      teamGridEl.querySelectorAll('.wpm-pk-team-btn').forEach(function (b) {
        b.classList.remove('is-selected');
      });
    }
    if (btnEl) { btnEl.classList.add('is-selected'); }

    if (teamHintEl) {
      teamHintEl.textContent = 'Main sebagai ' + team.flag + ' ' + team.name + '. Klik/tap area gawang buat nembak — 5 tendangan, cetak gol sebanyak mungkin!';
    }
    if (teamFlagBadgeEl) { teamFlagBadgeEl.textContent = team.flag; }

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

  // ---- Audio — synthesized via Web Audio API, same envelope-tone
  // technique as air-hockey.js's revamp (see docs/DECISIONS.md, 30 Agu
  // 2026 "Games Hub revamp" entry for the full reasoning: zero payload
  // bytes, zero licensing surface, vs. sourcing CC0 clips). Duplicated
  // here rather than imported from air-hockey.js on purpose — see this
  // file's top docblock. ----
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
    kick: function () { playTone([180, 90], 0.1, 'square', 0.07); },
    save: function () { playTone([300, 500], 0.12, 'square', 0.09); playTone(120, 0.1, 'square', 0.06, 0.03); },
    goal: function () {
      playTone(523.25, 0.12, 'triangle', 0.11, 0);
      playTone(659.25, 0.12, 'triangle', 0.11, 0.09);
      playTone(783.99, 0.16, 'triangle', 0.12, 0.18);
    },
    win: function () {
      playTone(523.25, 0.14, 'triangle', 0.12, 0);
      playTone(659.25, 0.14, 'triangle', 0.12, 0.13);
      playTone(783.99, 0.14, 'triangle', 0.12, 0.26);
      playTone(1046.5, 0.28, 'triangle', 0.13, 0.39);
    },
    lose: function () {
      playTone(392.0, 0.18, 'sawtooth', 0.08, 0);
      playTone(329.63, 0.18, 'sawtooth', 0.08, 0.16);
      playTone(261.63, 0.32, 'sawtooth', 0.08, 0.32);
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

  // ---- Particle burst + flash — same small hand-rolled system as
  // air-hockey.js's revamp, duplicated (not shared) per this file's top
  // docblock. ----
  var particles = [];
  var flashAlpha = 0;
  var flashColor = '255,255,255';

  function spawnBurst(x, y, rgbTriplet, count) {
    for (var i = 0; i < count; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 1.5 + Math.random() * 3.5;
      particles.push({
        x: x, y: y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        life: 1,
        decay: 0.02 + Math.random() * 0.03,
        r: 2 + Math.random() * 2.5,
        color: 'rgb(' + rgbTriplet + ')',
      });
    }
  }

  function triggerFlash(rgbTriplet) {
    flashAlpha = 0.3;
    flashColor = rgbTriplet;
  }

  function updateParticles() {
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      p.x += p.vx; p.y += p.vy;
      p.vx *= 0.96; p.vy *= 0.96;
      p.life -= p.decay;
      if (p.life <= 0) { particles.splice(i, 1); }
    }
    if (flashAlpha > 0) { flashAlpha = Math.max(0, flashAlpha - 0.05); }
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
    if (flashAlpha > 0) {
      ctx.fillStyle = 'rgba(' + flashColor + ',' + flashAlpha + ')';
      ctx.fillRect(0, 0, W, H);
    }
  }

  // ---- Difficulty: how often the AI keeper commits to the SAME zone
  // the player just kicked to. diveFrames is purely cosmetic (how long
  // the keeper's dive animation takes) — the save/goal outcome is
  // already decided the instant the player clicks, same as a real
  // keeper reading the run-up rather than the ball's flight. ----
  var DIFFICULTY_TUNING = {
    easy:   { correctChance: 0.15, diveFrames: 24 },
    medium: { correctChance: 0.40, diveFrames: 18 },
    hard:   { correctChance: 0.65, diveFrames: 13 },
  };

  var selectedDifficulty = 'medium';
  difficultyBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      difficultyBtns.forEach(function (b) { b.classList.remove('is-selected'); });
      btn.classList.add('is-selected');
      selectedDifficulty = btn.getAttribute('data-difficulty') || 'medium';
    });
  });

  var running = false;
  var rafId = null;
  var state = null;

  function resetShootout(difficulty) {
    state = {
      difficulty: difficulty,
      shotsTaken: 0,
      goals: 0,
      // 'aiming' = waiting for a click; 'kicking' = kicker wind-up/swing
      // (2 Sep 2026 visual revision — added purely so there's a visible
      // "someone is striking the ball" beat before it moves; the
      // goal/save DECISION already happened in attemptKick() before this
      // phase even starts, so nothing about outcome/scoring depends on
      // it, see pickKeeperZone() call site); 'animating' = ball/keeper
      // mid-flight (unchanged from the original gameplay brief);
      // 'resolved' = brief pause showing the outcome before the next kick.
      phase: 'aiming',
      ball: { x: BALL_START_X, y: BALL_START_Y },
      keeper: { x: W / 2, y: GOAL_TOP + GOAL_H * 0.58 },
      shotZone: null,
      keeperZone: null,
      kickFrame: 0,
      kickLength: 16,
      animFrame: 0,
      animLength: 26,
      diveLength: DIFFICULTY_TUNING[difficulty].diveFrames,
      outcome: null, // 'goal' | 'saved'
      pauseFrames: 0,
    };
  }

  function nearestZone(x, y) {
    var best = ZONES[0];
    var bestDist = Infinity;
    for (var i = 0; i < ZONES.length; i++) {
      var dx = ZONES[i].x - x;
      var dy = ZONES[i].y - y;
      var d = dx * dx + dy * dy;
      if (d < bestDist) { bestDist = d; best = ZONES[i]; }
    }
    return best;
  }

  function zoneById(id) {
    for (var i = 0; i < ZONES.length; i++) { if (ZONES[i].id === id) { return ZONES[i]; } }
    return ZONES[2]; // center fallback, never actually reached
  }

  function pickKeeperZone(shotZoneId) {
    var tuning = DIFFICULTY_TUNING[state.difficulty] || DIFFICULTY_TUNING.medium;
    if (Math.random() < tuning.correctChance) {
      return shotZoneId;
    }
    var others = ZONES.filter(function (z) { return z.id !== shotZoneId; });
    return others[Math.floor(Math.random() * others.length)].id;
  }

  function showStart() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    panelStart.hidden = false;
    boardEl.hidden = true;
    panelEnd.hidden = true;
  }

  function startShootout() {
    initAudio();
    resetShootout(selectedDifficulty);
    difficultyBadge.textContent = selectedDifficulty.toUpperCase();
    panelStart.hidden = true;
    panelEnd.hidden = true;
    boardEl.hidden = false;
    shotCountEl.textContent = '0/' + TOTAL_SHOTS;
    goalCountEl.textContent = '0';
    running = true;
    if (rafId) { cancelAnimationFrame(rafId); }
    rafId = requestAnimationFrame(loop);
  }

  startBtn.addEventListener('click', startShootout);
  restartBtn.addEventListener('click', showStart);
  playAgainBtn.addEventListener('click', startShootout);

  // ---- Input: click (desktop) + tap (mobile), same "map pointer into
  // logical coordinate space" approach as air-hockey.js. A kick only
  // registers while state.phase === 'aiming' — ignored mid-animation or
  // during the brief post-kick pause, so a fast double-tap can't queue
  // two kicks into one animation cycle. ----
  function pointerToLogical(clientX, clientY) {
    var rect = canvas.getBoundingClientRect();
    return {
      x: (clientX - rect.left) * (W / rect.width),
      y: (clientY - rect.top) * (H / rect.height),
    };
  }

  function attemptKick(x, y) {
    if (!state || !running || state.phase !== 'aiming') { return; }
    // Decision-making is UNCHANGED from the original gameplay logic —
    // shotZone/keeperZone are still locked in right here, at the instant
    // of the click (real-goalkeeper-reads-the-run-up reasoning, see
    // pickKeeperZone()'s own comment). Only what happens AFTER this is
    // new: instead of jumping straight into the ball-flight animation,
    // the 'kicking' wind-up phase plays first — draw() picks up
    // state.phase to animate the kicker figure, but the eventual
    // goal/save outcome is computed later purely from shotZone/keeperZone,
    // which are already final by this point. No new randomness, no
    // different odds — see docs/DECISIONS.md, 2 Sep 2026 visual revision
    // entry.
    var zone = nearestZone(x, y);
    state.shotZone = zone.id;
    state.keeperZone = pickKeeperZone(zone.id);
    state.phase = 'kicking';
    state.kickFrame = 0;
    // sfx.kick() fires later, in loop()'s 'kicking' -> 'animating'
    // transition — synced to the moment the kicker's foot actually
    // connects (end of the wind-up), not this initial click.
  }

  canvas.addEventListener('click', function (e) {
    var p = pointerToLogical(e.clientX, e.clientY);
    attemptKick(p.x, p.y);
  });

  canvas.addEventListener('touchend', function (e) {
    if (!e.changedTouches || !e.changedTouches[0]) { return; }
    var t = e.changedTouches[0];
    var p = pointerToLogical(t.clientX, t.clientY);
    attemptKick(p.x, p.y);
    e.preventDefault();
  }, { passive: false });

  // ---- Game loop ----
  function endShootout() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    var goals = state.goals;
    var tier;
    if (goals >= 5) { tier = 'Sempurna! ⚽⚽⚽⚽⚽'; }
    else if (goals >= 3) { tier = 'Bagus banget!'; }
    else if (goals >= 1) { tier = 'Lumayan, coba lagi!'; }
    else { tier = 'Yah, coba lagi!'; }
    endTitleEl.textContent = tier;
    endScoreEl.textContent = 'Gol: ' + goals + '/' + TOTAL_SHOTS;
    panelEnd.hidden = false;
    if (goals >= 3) { sfx.win(); } else { sfx.lose(); }
  }

  function loop() {
    if (!running || !state) { return; }

    if (state.phase === 'kicking') {
      // Kicker wind-up/swing (2 Sep 2026 visual revision) — purely a
      // render-timing delay. shotZone/keeperZone were already decided
      // in attemptKick(); this branch never touches them, never touches
      // score, and never runs the AI decision again. draw()'s
      // drawKicker() reads state.kickFrame/state.kickLength to animate
      // the figure; once the swing completes, hand off to the EXACT
      // same 'animating' phase logic that existed before this revision.
      state.kickFrame++;
      if (state.kickFrame >= state.kickLength) {
        sfx.kick();
        state.phase = 'animating';
        state.animFrame = 0;
      }
    } else if (state.phase === 'animating') {
      state.animFrame++;
      var t = Math.min(1, state.animFrame / state.animLength);
      var ease = 1 - Math.pow(1 - t, 2); // ease-out, feels like a real strike

      var targetShot = zoneById(state.shotZone);
      state.ball.x = BALL_START_X + (targetShot.x - BALL_START_X) * ease;
      state.ball.y = BALL_START_Y + (targetShot.y - BALL_START_Y) * ease;

      var diveT = Math.min(1, state.animFrame / state.diveLength);
      var targetKeeper = zoneById(state.keeperZone);
      var keeperHomeX = W / 2;
      var keeperHomeY = GOAL_TOP + GOAL_H * 0.58;
      state.keeper.x = keeperHomeX + (targetKeeper.x - keeperHomeX) * diveT;
      state.keeper.y = keeperHomeY + (targetKeeper.y - keeperHomeY) * diveT;

      if (state.animFrame >= state.animLength) {
        var outcome = state.shotZone === state.keeperZone ? 'saved' : 'goal';
        state.outcome = outcome;
        state.shotsTaken++;
        if (outcome === 'goal') {
          state.goals++;
          goalCountEl.textContent = String(state.goals);
          sfx.goal();
          spawnBurst(state.ball.x, state.ball.y, '57,255,136', 20);
          triggerFlash('57,255,136');
        } else {
          sfx.save();
          spawnBurst(state.keeper.x, state.keeper.y, '255,61,154', 14);
          triggerFlash('255,61,154');
        }
        shotCountEl.textContent = state.shotsTaken + '/' + TOTAL_SHOTS;
        state.phase = 'resolved';
        state.pauseFrames = 0;
      }
    } else if (state.phase === 'resolved') {
      state.pauseFrames++;
      if (state.pauseFrames >= 45) {
        if (state.shotsTaken >= TOTAL_SHOTS) {
          updateParticles();
          draw();
          endShootout();
          return;
        }
        state.ball.x = BALL_START_X;
        state.ball.y = BALL_START_Y;
        state.keeper.x = W / 2;
        state.keeper.y = GOAL_TOP + GOAL_H * 0.58;
        state.outcome = null;
        state.kickFrame = 0;
        state.phase = 'aiming';
      }
    }

    updateParticles();
    draw();
    rafId = requestAnimationFrame(loop);
  }

  // ---- Rendering ----
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
    var g = ctx.createRadialGradient(W / 2, H * 0.7, 30, W / 2, H * 0.7, H * 0.9);
    g.addColorStop(0, '#14502a');
    g.addColorStop(0.6, '#0b3d1f');
    g.addColorStop(1, '#062712');
    pitchGradient = g;
    return g;
  }

  // Depth offset for the fake-3D goal (2 Sep 2026 visual polish, round 2
  // — operator reference screenshot showed a goal with real depth/
  // perspective, not a flat rectangle). Purely a rendering trick: a
  // second, smaller "back" frame set above+inset from the front frame,
  // joined by short strut lines — no real 3D transform, just enough of
  // a cue to read as a box instead of a picture-frame outline.
  var GOAL_DEPTH_X = 10;
  var GOAL_DEPTH_Y = 16;

  function drawGoal() {
    var backLeft = GOAL_LEFT + GOAL_DEPTH_X;
    var backRight = GOAL_RIGHT - GOAL_DEPTH_X;
    var backTop = GOAL_TOP - GOAL_DEPTH_Y;

    // Ground shadow, grounds the whole structure on the pitch.
    ctx.beginPath();
    ctx.ellipse(W / 2, GOAL_BOTTOM + 4, GOAL_W / 2, 6, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,0.28)';
    ctx.fill();

    // Net "roof" — the trapezoid between the front crossbar and the
    // recessed back bar, so the net reads as going INTO the goal
    // instead of sitting on one flat plane.
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(GOAL_LEFT, GOAL_TOP);
    ctx.lineTo(GOAL_RIGHT, GOAL_TOP);
    ctx.lineTo(backRight, backTop);
    ctx.lineTo(backLeft, backTop);
    ctx.closePath();
    ctx.clip();
    ctx.strokeStyle = 'rgba(255,255,255,0.22)';
    ctx.lineWidth = 1;
    var roofStep = 16;
    for (var rx = GOAL_LEFT - 20; rx <= GOAL_RIGHT + 20; rx += roofStep) {
      ctx.beginPath(); ctx.moveTo(rx, GOAL_TOP); ctx.lineTo(rx - 6, backTop); ctx.stroke();
    }
    ctx.beginPath(); ctx.moveTo(GOAL_LEFT, GOAL_TOP + 4); ctx.lineTo(backLeft, backTop + 4); ctx.stroke();
    ctx.restore();

    // Net — front face crosshatch, clipped to the front frame (unchanged
    // technique from before this pass, just kept as its own block).
    ctx.save();
    ctx.beginPath();
    ctx.rect(GOAL_LEFT, GOAL_TOP, GOAL_W, GOAL_H);
    ctx.clip();
    ctx.strokeStyle = 'rgba(255,255,255,0.18)';
    ctx.lineWidth = 1;
    var step = 14;
    for (var gx = GOAL_LEFT; gx <= GOAL_RIGHT; gx += step) {
      ctx.beginPath(); ctx.moveTo(gx, GOAL_TOP); ctx.lineTo(gx, GOAL_BOTTOM); ctx.stroke();
    }
    for (var gy = GOAL_TOP; gy <= GOAL_BOTTOM; gy += step) {
      ctx.beginPath(); ctx.moveTo(GOAL_LEFT, gy); ctx.lineTo(GOAL_RIGHT, gy); ctx.stroke();
    }
    ctx.restore();

    // Back top bar (dimmer — further away).
    ctx.strokeStyle = 'rgba(244,247,255,0.55)';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(backLeft, backTop);
    ctx.lineTo(backRight, backTop);
    ctx.stroke();

    // Depth struts (top corners, front -> back) — sells the "box" shape.
    ctx.strokeStyle = 'rgba(244,247,255,0.45)';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(GOAL_LEFT, GOAL_TOP); ctx.lineTo(backLeft, backTop);
    ctx.moveTo(GOAL_RIGHT, GOAL_TOP); ctx.lineTo(backRight, backTop);
    ctx.stroke();

    // Front frame (posts + crossbar) — nearest camera, brightest.
    ctx.strokeStyle = '#f4f7ff';
    ctx.lineWidth = 5;
    ctx.lineJoin = 'round';
    ctx.shadowColor = 'rgba(244,247,255,0.6)';
    ctx.shadowBlur = 8;
    ctx.strokeRect(GOAL_LEFT, GOAL_TOP, GOAL_W, GOAL_H);
    ctx.shadowBlur = 0;

    // Post base "socks" — small dark ellipses where each post meets the
    // grass, a cheap but effective grounding cue.
    ctx.fillStyle = 'rgba(0,0,0,0.35)';
    ctx.beginPath(); ctx.ellipse(GOAL_LEFT, GOAL_BOTTOM + 2, 5, 2.2, 0, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(GOAL_RIGHT, GOAL_BOTTOM + 2, 5, 2.2, 0, 0, Math.PI * 2); ctx.fill();
  }

  function drawZoneHints() {
    if (!state || state.phase !== 'aiming') { return; }
    for (var i = 0; i < ZONES.length; i++) {
      var z = ZONES[i];
      ctx.beginPath();
      ctx.arc(z.x, z.y, 16, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(53,230,255,0.35)';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }
  }

  /**
   * Goalkeeper — a small stick figure (head/torso/arms/legs), not the
   * old MVP's bare 2-circle blob (2 Sep 2026 visual revision — operator
   * feedback: "ga kliatan ky orang lagi nendang... kya bukan pinalti
   * kick"). Gold/yellow glow to read as clearly distinct from the
   * kicker (cyan) and the ball (white/orange) at a glance. Drawn in
   * LOCAL coordinates inside a translate+rotate so the whole figure
   * (not just its position) leans toward whichever zone it's diving to
   * — `tilt` is 0 when standing at the home/center position and grows
   * with horizontal displacement, purely a render-time computation from
   * the EXISTING keeper.x/keeper.y (still driven by the same
   * diveT interpolation in loop() as before this revision — this
   * function never touches that logic, only how the point gets drawn).
   */
  function drawKeeper(x, y) {
    var color = '#ffd23f';
    var homeX = W / 2;
    var tiltMax = 0.5;
    var tilt = Math.max(-tiltMax, Math.min(tiltMax, (x - homeX) / 60));

    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(tilt);

    // Glow halo.
    ctx.beginPath();
    ctx.arc(0, 0, 22, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.16;
    ctx.shadowColor = color;
    ctx.shadowBlur = 20;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;

    var headR = 5;
    var torsoLen = 12;
    var legLen = 10;
    var hipY = 4;
    var shoulderY = hipY - torsoLen;
    var headY = shoulderY - headR - 1;

    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 10;

    // Legs (stable stance).
    ctx.beginPath();
    ctx.moveTo(0, hipY); ctx.lineTo(-6, hipY + legLen);
    ctx.moveTo(0, hipY); ctx.lineTo(6, hipY + legLen);
    ctx.stroke();

    // Torso.
    ctx.beginPath();
    ctx.moveTo(0, hipY); ctx.lineTo(0, shoulderY);
    ctx.stroke();

    // Arms spread wide — goalkeeper "ready"/dive pose.
    ctx.beginPath();
    ctx.moveTo(0, shoulderY); ctx.lineTo(-18, shoulderY - 6);
    ctx.moveTo(0, shoulderY); ctx.lineTo(18, shoulderY - 6);
    ctx.stroke();

    // Head.
    ctx.beginPath();
    ctx.arc(0, headY, headR, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
    ctx.shadowBlur = 0;
  }

  /**
   * Penalty taker — new in this revision (there was no kicker figure at
   * all before, just the ball appearing to move on its own; see this
   * file's 2 Sep 2026 visual-revision docblock note). Pose depends on
   * state.phase:
   *   - 'aiming': relaxed ready stance, standing next to the ball.
   *   - 'kicking': animated wind-up (leg swings back) then strike (leg
   *     swings forward through contact) driven by state.kickFrame —
   *     this is the ONLY place kickFrame is read; it never influences
   *     shotZone/keeperZone/outcome.
   *   - 'animating'/'resolved': frozen follow-through pose (kick already
   *     happened, ball is now in flight/result is showing).
   * Cyan glow — this game's own accent color (games-landing.css's
   * --card-accent for penalty-kick), distinct from the keeper's gold and
   * the ball's white/orange.
   */
  function drawKicker() {
    if (!state) { return; }
    var color = '#35e6ff';
    var hipX = BALL_START_X - 24;
    var hipY = BALL_START_Y + 6;
    var torsoLen = 13;
    var legLen = 13;
    var headR = 4;

    var legSwing = -0.15; // idle stance, leg slightly back
    var torsoLean = 0;
    if (state.phase === 'kicking') {
      var kt = Math.min(1, state.kickFrame / state.kickLength);
      if (kt < 0.4) {
        // Wind-up — leg swings back, away from the ball.
        var backT = kt / 0.4;
        legSwing = -0.15 - 0.75 * backT;
      } else {
        // Strike — fast forward swing through contact (ease-in: starts
        // slow, accelerates, like a real kick).
        var fwdT = (kt - 0.4) / 0.6;
        var eased = fwdT * fwdT;
        legSwing = -0.9 + 1.4 * eased;
      }
      torsoLean = legSwing * 0.25;
    } else if (state.phase === 'animating' || state.phase === 'resolved') {
      legSwing = 0.5; // frozen follow-through
      torsoLean = 0.13;
    }

    var shoulderX = hipX + Math.sin(torsoLean) * torsoLen * 0.3;
    var shoulderY = hipY - torsoLen;
    var headX = shoulderX + Math.sin(torsoLean) * headR;
    var headY = shoulderY - headR - 2;

    // Glow halo.
    ctx.beginPath();
    ctx.arc(hipX, hipY - torsoLen / 2, 16, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.14;
    ctx.shadowColor = color;
    ctx.shadowBlur = 16;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;

    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 8;

    // Support leg (planted, fixed).
    ctx.beginPath();
    ctx.moveTo(hipX, hipY);
    ctx.lineTo(hipX - 3, hipY + legLen);
    ctx.stroke();

    // Kicking leg — angle driven by legSwing above.
    var footX = hipX + Math.sin(legSwing) * legLen;
    var footY = hipY + Math.cos(legSwing) * legLen;
    ctx.beginPath();
    ctx.moveTo(hipX, hipY);
    ctx.lineTo(footX, footY);
    ctx.stroke();

    // Torso.
    ctx.beginPath();
    ctx.moveTo(hipX, hipY);
    ctx.lineTo(shoulderX, shoulderY);
    ctx.stroke();

    // Arms, out for balance (counter-lean against the torso).
    ctx.beginPath();
    ctx.moveTo(shoulderX, shoulderY);
    ctx.lineTo(shoulderX - 8, shoulderY + 4 - torsoLean * 6);
    ctx.moveTo(shoulderX, shoulderY);
    ctx.lineTo(shoulderX + 8, shoulderY - 2 + torsoLean * 6);
    ctx.stroke();

    // Head.
    ctx.beginPath();
    ctx.arc(headX, headY, headR, 0, Math.PI * 2);
    ctx.fill();

    ctx.shadowBlur = 0;
  }

  /**
   * Simplified soccer-ball pattern (2 Sep 2026 visual polish, round 2 —
   * operator reference showed a real pentagon-panel ball, not a plain
   * white disc). One central pentagon + radiating seam lines — a full
   * pentagon/hexagon tessellation is unnecessary at this render size
   * and would cost far more draw calls for no visible gain; this reads
   * correctly as "soccer ball" at normal viewing distance.
   */
  function drawBallPattern(x, y, r) {
    var sides = 5;
    var pR = r * 0.52;
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

  /**
   * `scale` (optional, defaults to 1) shrinks the ball as it travels
   * toward the goal — a cheap pseudo-depth cue computed purely from
   * state.ball.y at the call site in draw() below (see BALL_START_Y vs
   * GOAL_TOP), never from anything that affects physics/outcome; the
   * actual flight path (state.ball.x/y) is still driven by the exact
   * same ease-out interpolation in loop() as before this pass.
   */
  function drawBall(x, y, scale) {
    var s = scale || 1;
    var r = BALL_R * s;

    // Ground shadow — squashes/fades as the ball shrinks with distance.
    ctx.beginPath();
    ctx.ellipse(x, y + r * 0.9, r * 1.1, r * 0.35, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,' + (0.25 * s).toFixed(2) + ')';
    ctx.fill();

    // Warm glow halo — kept from the neon "gamer" language used
    // elsewhere (mallets/puck in air-hockey.js, keeper/kicker above).
    ctx.beginPath();
    ctx.arc(x, y, r * 1.5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,176,59,0.22)';
    ctx.shadowColor = 'rgba(255,176,59,0.8)';
    ctx.shadowBlur = 12;
    ctx.fill();
    ctx.shadowBlur = 0;

    // Sphere body — radial gradient (light upper-left, darker
    // lower-right) for pseudo-3D volume instead of a flat white disc.
    var grad = ctx.createRadialGradient(x - r * 0.35, y - r * 0.35, r * 0.15, x, y, r);
    grad.addColorStop(0, '#ffffff');
    grad.addColorStop(0.65, '#eef1f5');
    grad.addColorStop(1, '#b9c2cc');
    ctx.beginPath();
    ctx.arc(x, y, r, 0, Math.PI * 2);
    ctx.fillStyle = grad;
    ctx.fill();

    drawBallPattern(x, y, r);

    ctx.strokeStyle = 'rgba(0,0,0,0.25)';
    ctx.lineWidth = Math.max(0.6, r * 0.06);
    ctx.beginPath();
    ctx.arc(x, y, r, 0, Math.PI * 2);
    ctx.stroke();
  }

  function draw() {
    ctx.fillStyle = getPitchGradient();
    ctx.fillRect(0, 0, W, H);

    // Faint mow-stripe bands, same texture technique as air-hockey.js.
    ctx.fillStyle = 'rgba(255,255,255,0.02)';
    for (var stripe = 0; stripe < H; stripe += 30) {
      if ((stripe / 30) % 2 === 0) { ctx.fillRect(0, stripe, W, 30); }
    }

    // Penalty spot + arc, purely decorative context.
    ctx.beginPath();
    ctx.arc(BALL_START_X, BALL_START_Y, 2.5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(BALL_START_X, BALL_START_Y, 26, Math.PI * 1.15, Math.PI * 1.85);
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    drawGoal();

    if (!state) { drawBall(BALL_START_X, BALL_START_Y); return; }

    drawZoneHints();
    drawKicker();
    drawKeeper(state.keeper.x, state.keeper.y);
    // Depth scale: 1 at the penalty spot (closest to camera), shrinking
    // toward ~0.55 by the goal line — purely a render-time value derived
    // from the ball's existing y position, never fed back into
    // physics/state.
    var depthT = Math.max(0, Math.min(1, (BALL_START_Y - state.ball.y) / (BALL_START_Y - GOAL_TOP)));
    var ballScale = 1 - depthT * 0.45;
    drawBall(state.ball.x, state.ball.y, ballScale);
    drawParticles();
  }

  // Idle preview before a shootout starts.
  draw();
})();
