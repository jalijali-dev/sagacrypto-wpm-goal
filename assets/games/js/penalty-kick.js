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
 * Gameplay (8 Sep 2026, "mode gantian penuh" revision — see
 * resetShootout()'s own docblock below for the full writeup): a real
 * 1-vs-1 shootout, 5 rounds, each round has the player kick (CPU keeps
 * goal) THEN the CPU kicks (player keeps goal) — turns swap after every
 * single kick, not just at the start. Whoever is kicking picks a zone by
 * clicking/tapping one of 5 spots in the goal (top-left/top-right/
 * center/bottom-left/bottom-right); whoever is keeping either has the AI
 * commit to a zone (pickKeeperZone(), when CPU keeps) or clicks their
 * own dive zone (when the player keeps, defending a CPU shot). No
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
  var turnBannerEl = document.getElementById('pk-turn-banner');
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
  // `color` (8 Sep 2026, operator request "bikin warna orangnya sesuai
  // bendara yg diplih") — approximate each nation's iconic kit/flag
  // color, used to recolor whichever stick figure represents the PLAYER
  // (kicker when the player is taking a shot, keeper when the player is
  // defending a CPU shot — see PLAYER_COLOR/CPU_COLOR below). Purely
  // cosmetic, same as `flag` — never read by gameplay/scoring/AI code.
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
  // Fixed "away kit" color for whichever figure represents the CPU
  // (kicker when the CPU is shooting, keeper when the CPU is defending)
  // — always this one color regardless of the player's team pick, so the
  // two figures on screen are always visually distinct from each other.
  var CPU_COLOR = '#ff3d5a';
  function playerColor() { return (selectedTeam && selectedTeam.color) || '#35e6ff'; }

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
      teamHintEl.textContent = 'Main sebagai ' + team.flag + ' ' + team.name + '. 5 ronde, gantian jadi penendang dan kiper — klik/tap gawang buat nembak ATAU nangkep, tergantung giliran!';
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
  var TOTAL_ROUNDS = 5;

  // ---- Alternating shootout (8 Sep 2026, operator request "pinalti ini
  // dibuat gantian antara user dengan komputer, gantian pinalti dan
  // gantian siapa yg jadi kiper") — REPLACES the old "player always
  // kicks, CPU always keeps" structure with a real 1-vs-1 shootout: each
  // of TOTAL_ROUNDS rounds has the player kick first (CPU keeps goal,
  // unchanged pickKeeperZone() logic below) then the CPU kicks (player
  // keeps goal — player's click now picks the KEEPER zone instead of the
  // shot zone). state.turn tracks whose kick it currently is ('player' |
  // 'cpu'); state.stage tracks whether that round's player-kick or
  // cpu-kick half has happened yet. Winner is decided by total goals
  // after all rounds — no sudden-death tie-break, kept simple per the
  // original brief's "hindari physics/logic rumit" guidance. ----
  function resetShootout(difficulty) {
    state = {
      difficulty: difficulty,
      round: 1,
      totalRounds: TOTAL_ROUNDS,
      turn: 'player', // 'player' = player is kicking this attempt; 'cpu' = CPU is kicking, player defends
      playerGoals: 0,
      cpuGoals: 0,
      shotsTaken: 0, // total attempts resolved so far (both sides combined), drives the "Ronde" progress readout
      // 'aiming' = waiting for input (a click, meaning either "where I'm
      // shooting" or "where I'm diving" depending on state.turn);
      // 'kicking' = kicker wind-up/swing (purely a render-timing delay,
      // see drawKicker()'s docblock — the goal/save decision already
      // happened in attemptAction() before this phase starts);
      // 'animating' = ball/keeper mid-flight; 'resolved' = brief pause
      // showing the outcome before the next attempt.
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

  // CPU picking its OWN shot zone (used on 'cpu' turns, player defends).
  // Higher difficulty -> CPU aims for the corners more often (harder to
  // guess-dive correctly) instead of the easier-to-save center — mirrors
  // pickKeeperZone()'s difficulty curve but for the opposite role.
  var CPU_CENTER_CHANCE = { easy: 0.55, medium: 0.32, hard: 0.15 };
  function pickCpuShotZone(difficulty) {
    var centerChance = CPU_CENTER_CHANCE[difficulty] != null ? CPU_CENTER_CHANCE[difficulty] : 0.32;
    if (Math.random() < centerChance) { return 'center'; }
    var corners = ZONES.filter(function (z) { return z.id !== 'center'; });
    return corners[Math.floor(Math.random() * corners.length)].id;
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
    updateScoreboard();
    running = true;
    if (rafId) { cancelAnimationFrame(rafId); }
    rafId = requestAnimationFrame(loop);
  }

  // Refreshes every scoreboard readout from state — round counter, dual
  // score (Kamu/CPU), and whose-turn/what-role banner. Called once at
  // shootout start and again every time a round/turn changes in loop().
  function updateScoreboard() {
    if (!state) { return; }
    if (shotCountEl) { shotCountEl.textContent = state.round + '/' + state.totalRounds; }
    if (goalCountEl) { goalCountEl.textContent = state.playerGoals + ' - ' + state.cpuGoals; }
    if (turnBannerEl) {
      if (state.turn === 'player') {
        turnBannerEl.textContent = (selectedTeam ? selectedTeam.flag + ' ' : '') + 'Giliranmu menendang!';
        turnBannerEl.className = 'wpm-pk-turn-banner wpm-pk-turn-banner--kick';
      } else {
        turnBannerEl.textContent = 'CPU menendang — kamu jadi kiper!';
        turnBannerEl.className = 'wpm-pk-turn-banner wpm-pk-turn-banner--keep';
      }
    }
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

  // Renamed from attemptKick() (8 Sep 2026, alternating-turns revision)
  // — a click now means two different things depending on state.turn:
  //   - turn === 'player': same as the original gameplay logic, click
  //     picks the SHOT zone; the CPU keeper's zone is decided right here
  //     via pickKeeperZone() (unchanged AI, unchanged difficulty odds).
  //   - turn === 'cpu': the CPU already auto-picked its shot zone the
  //     moment this turn began (see loop()'s 'resolved' branch below,
  //     where pickCpuShotZone() is called) — the click here instead
  //     picks the KEEPER zone (the player defending). state.shotZone is
  //     already set going in; this branch only fills state.keeperZone.
  // Either way, once both zones are locked in, the exact same
  // 'kicking' -> 'animating' -> 'resolved' pipeline plays out — the
  // ball-flight/dive animation code never needs to know who's kicking.
  function attemptAction(x, y) {
    if (!state || !running || state.phase !== 'aiming') { return; }
    var zone = nearestZone(x, y);
    if (state.turn === 'player') {
      state.shotZone = zone.id;
      state.keeperZone = pickKeeperZone(zone.id);
    } else {
      state.keeperZone = zone.id;
      // state.shotZone was already set when this CPU turn began.
    }
    state.phase = 'kicking';
    state.kickFrame = 0;
    // sfx.kick() fires later, in loop()'s 'kicking' -> 'animating'
    // transition — synced to the moment the kicker's foot actually
    // connects (end of the wind-up), not this initial click.
  }

  canvas.addEventListener('click', function (e) {
    var p = pointerToLogical(e.clientX, e.clientY);
    attemptAction(p.x, p.y);
  });

  canvas.addEventListener('touchend', function (e) {
    if (!e.changedTouches || !e.changedTouches[0]) { return; }
    var t = e.changedTouches[0];
    var p = pointerToLogical(t.clientX, t.clientY);
    attemptAction(p.x, p.y);
    e.preventDefault();
  }, { passive: false });

  // ---- Game loop ----
  // Winner decided by total goals across both roles after all
  // TOTAL_ROUNDS rounds — no sudden-death tie-break (kept simple, same
  // "avoid extra complexity" spirit as the original brief).
  function endShootout() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    var p = state.playerGoals, c = state.cpuGoals;
    var title, won;
    if (p > c) { title = 'Kamu Menang! 🏆'; won = true; }
    else if (p < c) { title = 'Kamu Kalah'; won = false; }
    else { title = 'Seri!'; won = null; }
    endTitleEl.textContent = title;
    endScoreEl.textContent = (selectedTeam ? selectedTeam.flag + ' ' : '') + 'Kamu ' + p + ' - ' + c + ' CPU';
    panelEnd.hidden = false;
    if (won === false) { sfx.lose(); } else { sfx.win(); }
  }

  function loop() {
    if (!running || !state) { return; }

    if (state.phase === 'kicking') {
      // Kicker wind-up/swing (2 Sep 2026 visual revision) — purely a
      // render-timing delay. shotZone/keeperZone were already decided
      // in attemptAction(); this branch never touches them, never touches
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
        if (outcome === 'goal') {
          if (state.turn === 'player') { state.playerGoals++; } else { state.cpuGoals++; }
          sfx.goal();
          spawnBurst(state.ball.x, state.ball.y, '57,255,136', 20);
          triggerFlash('57,255,136');
        } else {
          sfx.save();
          spawnBurst(state.keeper.x, state.keeper.y, '255,61,154', 14);
          triggerFlash('255,61,154');
        }
        state.phase = 'resolved';
        state.pauseFrames = 0;
      }
    } else if (state.phase === 'resolved') {
      state.pauseFrames++;
      if (state.pauseFrames >= 45) {
        if (state.turn === 'player') {
          // Player's kick this round is done -> CPU's turn to kick, player defends.
          state.turn = 'cpu';
          state.shotZone = pickCpuShotZone(state.difficulty); // CPU decides its shot NOW, before player dives
          state.keeperZone = null;
        } else {
          // CPU's kick this round is done -> round complete.
          state.round++;
          if (state.round > state.totalRounds) {
            updateParticles();
            draw();
            endShootout();
            return;
          }
          state.turn = 'player';
          state.shotZone = null;
          state.keeperZone = null;
        }
        state.shotsTaken++;
        state.ball.x = BALL_START_X;
        state.ball.y = BALL_START_Y;
        state.keeper.x = W / 2;
        state.keeper.y = GOAL_TOP + GOAL_H * 0.58;
        state.outcome = null;
        state.kickFrame = 0;
        state.phase = 'aiming';
        updateScoreboard();
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
  // Bumped up from 10/16 (2 Sep 2026, stronger pseudo-3D pass — operator
  // asked for a more convincing vanishing point after the first attempt
  // still read as fairly flat).
  var GOAL_DEPTH_X = 18;
  var GOAL_DEPTH_Y = 26;

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

    // Darken the roof toward the back bar — the "further away = dimmer"
    // cue the brief asked for, layered as a gradient overlay rather than
    // changing the net-line strokes above (keeps the crosshatch itself
    // unchanged, just tints what's already there).
    var roofShade = ctx.createLinearGradient(0, GOAL_TOP, 0, backTop);
    roofShade.addColorStop(0, 'rgba(0,0,0,0)');
    roofShade.addColorStop(1, 'rgba(0,0,0,0.5)');
    ctx.fillStyle = roofShade;
    ctx.fillRect(GOAL_LEFT - 20, backTop, GOAL_W + 40, GOAL_TOP - backTop);
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

  /**
   * Darkens (negative percent) or lightens (positive) a 6-digit hex
   * color by roughly `percent`% per channel — used below to derive a
   * "shorts"/"socks" shade from each figure's jersey `color` so the
   * two body pieces read as visually distinct blocks (like a real kit)
   * instead of one flat silhouette. Only ever fed a static hex string
   * (TEAMS[].color or CPU_COLOR), never used for anything gameplay-
   * related.
   */
  function shadeColor(hex, percent) {
    var num = parseInt(hex.replace('#', ''), 16);
    var amt = Math.round(2.55 * percent);
    var r = Math.max(0, Math.min(255, (num >> 16) + amt));
    var g = Math.max(0, Math.min(255, ((num >> 8) & 0x00ff) + amt));
    var b = Math.max(0, Math.min(255, (num & 0x0000ff) + amt));
    return '#' + (0x1000000 + r * 0x10000 + g * 0x100 + b).toString(16).slice(1);
  }

  // drawZoneHints() (the 4 outline-ring click-target indicators) was
  // REMOVED here on 2 Sep 2026 per explicit operator feedback ("tanda
  // digawang nya itu gausah") — the 5 aimable ZONES themselves (and
  // nearestZone()'s click-to-zone mapping in attemptAction()) are
  // completely UNCHANGED; only this purely-visual ring indicator is
  // gone. Deliberately not replaced with a subtler hover-only affordance
  // either — brief explicitly said "kalau ragu, hilangkan saja", so this
  // leans on the literal request (a fully blank goal to aim at) rather
  // than devs inventing a new hint mechanism.

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
   *
   * `color` (8 Sep 2026, "warna orangnya sesuai bendera yg dipilih" +
   * "gantung ditengah" fixes) — now takes the figure's color as a
   * parameter instead of a hardcoded gold, since this function draws
   * whichever side (player or CPU) is currently in goal — see draw()'s
   * call site for how kickerColor/keeperColor are picked per state.turn.
   * Also scaled up ~18% (headR/torsoLen/legLen) and given a solid torso
   * fill + stronger ground shadow so the figure reads as "grounded"/
   * has visual weight instead of a thin skeleton floating mid-frame
   * (operator feedback: "kelihatan gantung ditengah").
   */
  function drawKeeper(x, y, color) {
    color = color || '#ffd23f'; // fallback if ever called without one
    var homeX = W / 2;
    var tiltMax = 0.5;
    var tilt = Math.max(-tiltMax, Math.min(tiltMax, (x - homeX) / 60));

    // Ground shadow (8 Sep 2026 — enlarged/darkened again from the 2 Sep
    // pass, part of the "grounded" fix: bigger + more opaque so the
    // keeper reads as standing ON the grass, not floating over it).
    // Drawn in world space, BEFORE the translate/rotate below, so it
    // stays flat on the grass instead of tilting with the figure.
    ctx.beginPath();
    ctx.ellipse(x, y + 18, 17, 4.5, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,0,0,0.4)';
    ctx.fill();

    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(tilt);

    // Glow halo.
    ctx.beginPath();
    ctx.arc(0, 0, 25, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.16;
    ctx.shadowColor = color;
    ctx.shadowBlur = 20;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;

    // Scaled up ~18% from the original 5/12/10 (8 Sep 2026 "grounded"
    // fix) so the figure fills more of the goal frame visually.
    var headR = 6;
    var torsoLen = 14;
    var legLen = 12;
    var hipY = 4;
    var shoulderY = hipY - torsoLen;
    var headY = shoulderY - headR - 1;
    var torsoW = 7; // half-width of the torso jersey block below
    var shortsColor = shadeColor(color, -28); // darker shade, reads as a separate kit piece
    var socksColor = shadeColor(color, -40);
    var shoeColor = '#161616';

    // ---- Legs: sock "capsule" (thick round-capped stroke) + a small
    // dark shoe ellipse at each foot (8 Sep 2026, pushing the "papercraft
    // kit" look further per operator feedback on a reference screenshot
    // — still 100% vector/Canvas, no image assets, just more body pieces
    // than the original single thin leg line). ----
    var footL = { x: -7, y: hipY + legLen };
    var footR = { x: 7, y: hipY + legLen };
    ctx.strokeStyle = socksColor;
    ctx.lineWidth = 5;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 6;
    ctx.beginPath();
    ctx.moveTo(0, hipY - 2); ctx.lineTo(footL.x, footL.y);
    ctx.moveTo(0, hipY - 2); ctx.lineTo(footR.x, footR.y);
    ctx.stroke();
    ctx.shadowBlur = 0;
    ctx.fillStyle = shoeColor;
    ctx.beginPath(); ctx.ellipse(footL.x, footL.y + 1, 4.2, 2.4, -0.2, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(footR.x, footR.y + 1, 4.2, 2.4, 0.2, 0, Math.PI * 2); ctx.fill();

    // ---- Shorts — small filled block bridging hip/upper-leg, drawn
    // BEFORE the jersey so the jersey's bottom edge overlaps it slightly
    // (same layering a real kit has: shirt untucked over shorts). ----
    ctx.shadowColor = color;
    ctx.shadowBlur = 4;
    ctx.fillStyle = shortsColor;
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(-torsoW * 0.75, hipY - 4, torsoW * 1.5, 9, 3);
    } else {
      ctx.rect(-torsoW * 0.75, hipY - 4, torsoW * 1.5, 9);
    }
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 10;

    // Solid torso "jersey" (8 Sep 2026 "grounded" fix) — a filled
    // rounded shape instead of a single stroked line, so the figure has
    // actual body mass/silhouette rather than reading as a bare
    // skeleton. Gradient (lighter center, darker edges) gives it a
    // touch of fabric-like volume rather than a flat color block.
    var torsoGrad = ctx.createLinearGradient(-torsoW, shoulderY, torsoW, hipY);
    torsoGrad.addColorStop(0, color);
    torsoGrad.addColorStop(0.5, '#ffffff');
    torsoGrad.addColorStop(1, color);
    ctx.globalAlpha = 0.9;
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(-torsoW, shoulderY, torsoW * 2, torsoLen, torsoW * 0.7);
    } else {
      ctx.rect(-torsoW, shoulderY, torsoW * 2, torsoLen);
    }
    ctx.fillStyle = torsoGrad;
    ctx.shadowBlur = 0;
    ctx.fill();
    ctx.globalAlpha = 1;
    ctx.shadowColor = color;
    ctx.shadowBlur = 10;

    // Arms spread wide — goalkeeper "ready"/dive pose — with a small
    // glove circle at each hand (8 Sep 2026), a cheap detail that reads
    // clearly as "goalkeeper" at this render size.
    var handL = { x: -20, y: shoulderY - 6 };
    var handR = { x: 20, y: shoulderY - 6 };
    ctx.beginPath();
    ctx.moveTo(0, shoulderY + 2); ctx.lineTo(handL.x, handL.y);
    ctx.moveTo(0, shoulderY + 2); ctx.lineTo(handR.x, handR.y);
    ctx.stroke();
    ctx.shadowBlur = 4;
    ctx.fillStyle = '#f4f7ff';
    ctx.beginPath(); ctx.arc(handL.x, handL.y, 2.6, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(handR.x, handR.y, 2.6, 0, Math.PI * 2); ctx.fill();
    ctx.shadowBlur = 0;

    // Head — radial gradient instead of flat fill (2 Sep 2026, stronger
    // pseudo-3D pass) for a touch of volume, same sphere-shading idea as
    // the ball's gradient below.
    var headGrad = ctx.createRadialGradient(-headR * 0.3, headY - headR * 0.3, headR * 0.2, 0, headY, headR);
    headGrad.addColorStop(0, '#fff3c4');
    headGrad.addColorStop(1, color);
    ctx.beginPath();
    ctx.arc(0, headY, headR, 0, Math.PI * 2);
    ctx.fillStyle = headGrad;
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
   * `color` (8 Sep 2026, "warna orangnya sesuai bendera yg dipilih" +
   * "gantung ditengah" fixes) — now takes the figure's color as a
   * parameter (was hardcoded cyan) — see draw()'s call site for how
   * kickerColor/keeperColor are picked per state.turn. Also scaled up
   * and given a solid torso fill, same "grounded" treatment as
   * drawKeeper() above, for visual consistency between the two figures.
   */
  function drawKicker(color) {
    if (!state) { return; }
    color = color || '#35e6ff'; // fallback if ever called without one
    var hipX = BALL_START_X - 24;
    var hipY = BALL_START_Y + 6;
    var torsoLen = 15;
    var legLen = 15;
    var headR = 5;

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

    // Ground shadow (8 Sep 2026, enlarged again as part of the
    // "grounded" fix, same reasoning as drawKeeper()'s) — the kicker
    // stands closest to camera, so this is still the biggest/darkest of
    // the three ground shadows in the scene, consistent with
    // "closer = bigger shadow" depth logic.
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

    // ---- Legs: sock "capsule" (thick stroke, darker shade of the kit
    // color) + a small dark shoe ellipse at each foot (8 Sep 2026,
    // pushing the "papercraft kit" look further per operator feedback
    // on a reference screenshot — still 100% vector/Canvas). ----
    var supportFootX = hipX - 3;
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
    // Kicking foot's shoe follows the swing angle so it reads as a real
    // strike/follow-through, not a static blob.
    ctx.beginPath(); ctx.ellipse(footX, footY, 4.4, 2.6, legSwing, 0, Math.PI * 2); ctx.fill();

    // ---- Shorts — small filled block at the hip, drawn before the
    // torso so the jersey overlaps its top edge slightly (same
    // shirt-over-shorts layering as drawKeeper()'s). ----
    ctx.shadowColor = color;
    ctx.shadowBlur = 4;
    ctx.fillStyle = shortsColor;
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(hipX - 6, hipY - 5, 12, 9, 3);
    } else {
      ctx.rect(hipX - 6, hipY - 5, 12, 9);
    }
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 8;

    // Torso — thick round-capped stroke instead of the old thin line
    // (8 Sep 2026 "grounded" fix, same intent as drawKeeper()'s solid
    // torso fill) — a cheap way to add visual bulk/weight without
    // needing a rotated polygon to track the torso's dynamic lean angle.
    ctx.save();
    ctx.lineWidth = 9;
    ctx.shadowBlur = 6;
    ctx.beginPath();
    ctx.moveTo(hipX, hipY);
    ctx.lineTo(shoulderX, shoulderY);
    ctx.stroke();
    ctx.restore();
    ctx.strokeStyle = color;
    ctx.lineWidth = 2.6;
    ctx.shadowColor = color;
    ctx.shadowBlur = 8;

    // Arms, out for balance (counter-lean against the torso).
    ctx.beginPath();
    ctx.moveTo(shoulderX, shoulderY);
    ctx.lineTo(shoulderX - 8, shoulderY + 4 - torsoLean * 6);
    ctx.moveTo(shoulderX, shoulderY);
    ctx.lineTo(shoulderX + 8, shoulderY - 2 + torsoLean * 6);
    ctx.stroke();

    // Head — radial gradient instead of flat fill (2 Sep 2026, same
    // touch of volume as the keeper's head/the ball's sphere shading).
    var kHeadGrad = ctx.createRadialGradient(headX - headR * 0.3, headY - headR * 0.3, headR * 0.2, headX, headY, headR);
    kHeadGrad.addColorStop(0, '#c8faff');
    kHeadGrad.addColorStop(1, color);
    ctx.beginPath();
    ctx.arc(headX, headY, headR, 0, Math.PI * 2);
    ctx.fillStyle = kHeadGrad;
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

  /**
   * Pitch lines that CONVERGE toward the goal instead of flat parallel
   * bands (2 Sep 2026, stronger pseudo-3D pass — operator asked for
   * perspective lines specifically). Two side edges narrow from the
   * near/bottom edge (closest to camera) to the goal's own width, plus
   * one crossing "box line" interpolated partway between — a cheap,
   * classic vanishing-point trick, not a real projection.
   */
  function drawPitchPerspectiveLines() {
    var nearLeftX = 10, nearRightX = W - 10, nearY = H - 2;
    var farLeftX = GOAL_LEFT - 6, farRightX = GOAL_RIGHT + 6, farY = GOAL_BOTTOM;

    ctx.strokeStyle = 'rgba(255,255,255,0.12)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(nearLeftX, nearY); ctx.lineTo(farLeftX, farY);
    ctx.moveTo(nearRightX, nearY); ctx.lineTo(farRightX, farY);
    ctx.stroke();

    var midT = 0.55;
    var midY = nearY + (farY - nearY) * midT;
    var midLeftX = nearLeftX + (farLeftX - nearLeftX) * midT;
    var midRightX = nearRightX + (farRightX - nearRightX) * midT;
    ctx.beginPath();
    ctx.moveTo(midLeftX, midY);
    ctx.lineTo(midRightX, midY);
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

    drawPitchPerspectiveLines();

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

    // Figure colors follow WHO is currently kicking/keeping, not a fixed
    // role (8 Sep 2026, "warna orangnya sesuai bendera yg dipilih") — the
    // player's figure (whichever role that is this turn) always uses
    // their selected team's color; the CPU's figure always uses
    // CPU_COLOR. Recomputed every frame from state.turn since the two
    // figures swap roles/colors each time the turn flips.
    var kickerColor = state.turn === 'player' ? playerColor() : CPU_COLOR;
    var keeperColor = state.turn === 'player' ? CPU_COLOR : playerColor();
    drawKicker(kickerColor);
    drawKeeper(state.keeper.x, state.keeper.y, keeperColor);
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
