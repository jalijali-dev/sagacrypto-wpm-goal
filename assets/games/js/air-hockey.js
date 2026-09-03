/**
 * Sagagoal Games — Air Hockey (30 Agu 2026, Games Hub MVP).
 *
 * Vanilla JS + Canvas 2D, zero dependencies, zero game engine — see
 * docs/DECISIONS.md for why (payload budget: operator wants this whole
 * page under ~200KB, a physics/engine library alone would blow that).
 * Circle-vs-circle (puck vs mallet) and circle-vs-line (puck vs
 * wall/goal) collision, written by hand — air hockey doesn't need more
 * than that. Game loop runs on requestAnimationFrame, never setInterval.
 *
 * Score is in-memory only (a plain JS variable) — no localStorage, no
 * network call, nothing persisted. Intentional for this MVP: there is no
 * backend leaderboard yet (see docs/DECISIONS.md, 30 Agu 2026 — sign-up
 * form + score persistence are a deliberately deferred later phase, not
 * an oversight here).
 */
(function () {
  'use strict';

  // ---- Logical coordinate space (independent of actual on-screen
  // pixel size — the canvas element is scaled via CSS/aspect-ratio in
  // air-hockey.css; this file only ever thinks in these units). ----
  var W = 400;
  var H = 600;
  var WALL = 10;              // visual table border thickness
  var GOAL_WIDTH = 130;
  var PUCK_R = 13;
  var MALLET_R = 26;
  var CENTER_Y = H / 2;
  var WIN_SCORE = 5;

  var canvas = document.getElementById('ah-canvas');
  var ctx = canvas ? canvas.getContext('2d') : null;

  var panelStart = document.getElementById('ah-panel-start');
  var panelEnd = document.getElementById('ah-panel-end');
  var boardEl = document.getElementById('ah-board');
  var startBtn = document.getElementById('ah-start-btn');
  var restartBtn = document.getElementById('ah-restart-btn');
  var playAgainBtn = document.getElementById('ah-play-again-btn');
  var difficultyBtns = document.querySelectorAll('.wpm-ah-difficulty__btn');
  var difficultyBadge = document.getElementById('ah-difficulty-badge');
  var scoreCpuEl = document.getElementById('ah-score-cpu');
  var scorePlayerEl = document.getElementById('ah-score-player');
  var endTitleEl = document.getElementById('ah-end-title');
  var endScoreEl = document.getElementById('ah-end-score');
  var muteBtn = document.getElementById('ah-mute-btn');

  if (!canvas || !ctx) { return; }

  // ---- Audio (30 Agu 2026 visual+audio revamp) ----------------------
  //
  // Every sound effect below is SYNTHESIZED at runtime with the Web
  // Audio API (a few oscillator + gain nodes per hit), not a pre-recorded
  // file — chosen deliberately over sourcing CC0 clips from
  // freesound.org/Kenney.nl:
  //   - Zero bytes added to the page's payload budget (the brief's whole
  //     concern here), vs. even a few dozen KB per short recorded clip.
  //   - Zero licensing/attribution surface at all — nothing external is
  //     used, so there's nothing to document a license for.
  //   - A handful of short square/sine blips is a well-established,
  //     genuinely lightweight aesthetic for this kind of small browser
  //     game (think classic arcade SFX), and fits the neon "gaming vibe"
  //     this whole zone is going for.
  // Background music was explicitly marked OPTIONAL in the brief and is
  // deliberately NOT implemented in this pass — every REQUIRED cue (wall
  // bounce, mallet hit, goal, win, lose) is covered below. See
  // docs/DECISIONS.md (30 Agu 2026 revamp entry) for the full writeup.
  //
  // AudioContext is created lazily on the first "Mulai Main" click (a
  // real user gesture) via initAudio() below, never at page load — both
  // because browsers block audio before a user gesture anyway, and
  // because the brief explicitly requires audio to never play
  // unprompted.
  var audioCtx = null;
  var audioEnabled = true;

  function initAudio() {
    if (audioCtx) {
      // Some browsers create a context in "suspended" state even from a
      // real click handler — resume() is a no-op if already running.
      if (audioCtx.state === 'suspended') { audioCtx.resume(); }
      return;
    }
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { return; } // very old browser — game still works, just silent
    try { audioCtx = new AC(); } catch (e) { audioCtx = null; }
  }

  /**
   * One short envelope-shaped tone: quick attack, exponential decay —
   * this is what makes a raw oscillator sound like a "blip" instead of a
   * flat, harsh beep. `freq` may be a single number or [start, end] for a
   * quick pitch sweep (used for the mallet-hit "thwack" and goal jingle).
   */
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
    wallBounce: function () { playTone(220, 0.07, 'square', 0.05); },
    malletHit: function () { playTone([420, 160], 0.09, 'square', 0.09); },
    goal: function () {
      // Quick 3-note rising arpeggio — reads as a "reward" ping without
      // being a long jingle.
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

  var selectedDifficulty = 'medium';
  var running = false;
  var rafId = null;

  var state = null; // set by resetMatch()

  function resetMatch(difficulty) {
    state = {
      difficulty: difficulty,
      scoreCpu: 0,
      scorePlayer: 0,
      puck: { x: W / 2, y: H / 2, vx: 0, vy: 0 },
      // Mallet objects track both current position and previous-frame
      // position so a per-frame velocity can be derived (used to give
      // the puck a "hit" impulse from the player's own swing speed, and
      // for the AI's own movement clamp).
      cpu: { x: W / 2, y: 70, px: W / 2, py: 70 },
      player: { x: W / 2, y: H - 70, px: W / 2, py: H - 70 },
      aiTarget: { x: W / 2, y: 70 },
      aiFrameCounter: 0,
      pointerActive: false,
      // Fading motion trail behind the puck (30 Agu 2026 revamp) — a
      // capped list of recent positions, oldest first; see draw()'s
      // drawTrail(). Purely cosmetic, never read by physics/AI.
      trail: [],
    };
    launchPuck(Math.random() < 0.5 ? 1 : -1);
  }

  function launchPuck(direction) {
    var angle = (Math.random() * 0.6 - 0.3); // slight random angle off vertical
    var speed = 3.2;
    state.puck.x = W / 2;
    state.puck.y = H / 2;
    state.puck.vx = Math.sin(angle) * speed;
    state.puck.vy = Math.cos(angle) * speed * direction;
  }

  // ---- Difficulty picker (start screen) ----
  difficultyBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      difficultyBtns.forEach(function (b) { b.classList.remove('is-selected'); });
      btn.classList.add('is-selected');
      selectedDifficulty = btn.getAttribute('data-difficulty') || 'medium';
    });
  });

  function showStart() {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    panelStart.hidden = false;
    boardEl.hidden = true;
    panelEnd.hidden = true;
  }

  function startMatch() {
    initAudio(); // must be called from a real click handler — see initAudio()'s own comment
    resetMatch(selectedDifficulty);
    difficultyBadge.textContent = selectedDifficulty.toUpperCase();
    panelStart.hidden = true;
    panelEnd.hidden = true;
    boardEl.hidden = false;
    scoreCpuEl.textContent = '0';
    scorePlayerEl.textContent = '0';
    running = true;
    if (rafId) { cancelAnimationFrame(rafId); }
    rafId = requestAnimationFrame(loop);
  }

  startBtn.addEventListener('click', startMatch);
  restartBtn.addEventListener('click', showStart);
  playAgainBtn.addEventListener('click', startMatch);

  // ---- Input: mouse (desktop) + touch (mobile), both map pointer
  // position into the same logical W x H coordinate space regardless of
  // the canvas's actual on-screen CSS size. ----
  function pointerToLogical(clientX, clientY) {
    var rect = canvas.getBoundingClientRect();
    var scaleX = W / rect.width;
    var scaleY = H / rect.height;
    return {
      x: (clientX - rect.left) * scaleX,
      y: (clientY - rect.top) * scaleY,
    };
  }

  function movePlayerTo(x, y) {
    if (!state) { return; }
    // Clamp to the player's own half + inside the walls, same margin
    // used everywhere else in this file (WALL + MALLET_R).
    var minX = WALL + MALLET_R;
    var maxX = W - WALL - MALLET_R;
    var minY = CENTER_Y + MALLET_R;
    var maxY = H - WALL - MALLET_R;
    state.player.x = Math.max(minX, Math.min(maxX, x));
    state.player.y = Math.max(minY, Math.min(maxY, y));
  }

  canvas.addEventListener('mousemove', function (e) {
    var p = pointerToLogical(e.clientX, e.clientY);
    movePlayerTo(p.x, p.y);
  });

  canvas.addEventListener('touchstart', function (e) {
    if (!state) { return; }
    state.pointerActive = true;
  }, { passive: true });

  canvas.addEventListener('touchmove', function (e) {
    if (!e.touches || !e.touches[0]) { return; }
    var t = e.touches[0];
    var p = pointerToLogical(t.clientX, t.clientY);
    movePlayerTo(p.x, p.y);
    e.preventDefault();
  }, { passive: false });

  // ---- AI (computer opponent) ----
  // Easy/Medium/Hard differ on THREE axes: how often the AI re-reads the
  // puck's position (reaction lag), how far it can move per frame (max
  // speed), and whether it predicts ahead of the puck's current position
  // (Hard only) — see docs/DECISIONS.md / the brief this file implements
  // for the intended feel of each tier.
  var AI_TUNING = {
    easy:   { reactionFrames: 14, maxSpeed: 2.0, predict: false, missChance: 0.10 },
    medium: { reactionFrames: 4,  maxSpeed: 3.4, predict: false, missChance: 0 },
    hard:   { reactionFrames: 1,  maxSpeed: 4.6, predict: true,  missChance: 0 },
  };

  function updateAi() {
    var tuning = AI_TUNING[state.difficulty] || AI_TUNING.medium;
    state.aiFrameCounter++;

    if (state.aiFrameCounter >= tuning.reactionFrames) {
      state.aiFrameCounter = 0;

      var targetX = state.puck.x;
      var targetY = Math.min(state.puck.y, CENTER_Y - MALLET_R - 10);

      if (tuning.predict && state.puck.vy < 0) {
        // Puck heading toward the AI's own goal — extrapolate a few
        // frames ahead (linear: position + velocity * lookahead) and
        // move to intercept that predicted point instead of chasing the
        // puck's current position, so Hard reads as "cutting the puck
        // off" rather than just "fast tracking".
        var lookahead = 14;
        targetX = state.puck.x + state.puck.vx * lookahead;
        // Reflect the prediction off the side walls the same way the
        // puck itself would bounce, so the target stays a plausible
        // on-table point instead of drifting outside the play area.
        var minX = WALL + PUCK_R;
        var maxX = W - WALL - PUCK_R;
        if (targetX < minX) { targetX = minX + (minX - targetX); }
        if (targetX > maxX) { targetX = maxX - (targetX - maxX); }
        targetY = Math.max(WALL + MALLET_R, Math.min(CENTER_Y - MALLET_R - 10, state.puck.y + state.puck.vy * lookahead));
      } else if (tuning.missChance > 0 && Math.random() < tuning.missChance) {
        // Easy occasionally "whiffs" — reads a deliberately offset,
        // stale-feeling target instead of the puck's real position.
        targetX += (Math.random() - 0.5) * 90;
      }

      var minTX = WALL + MALLET_R;
      var maxTX = W - WALL - MALLET_R;
      state.aiTarget.x = Math.max(minTX, Math.min(maxTX, targetX));
      state.aiTarget.y = Math.max(WALL + MALLET_R, Math.min(CENTER_Y - MALLET_R, targetY));
    }

    // Move toward aiTarget at a capped per-frame speed — this alone is
    // what makes Easy feel "slow to react" even between re-reads.
    var dx = state.aiTarget.x - state.cpu.x;
    var dy = state.aiTarget.y - state.cpu.y;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist > tuning.maxSpeed) {
      state.cpu.x += (dx / dist) * tuning.maxSpeed;
      state.cpu.y += (dy / dist) * tuning.maxSpeed;
    } else {
      state.cpu.x = state.aiTarget.x;
      state.cpu.y = state.aiTarget.y;
    }

    // Keep the CPU mallet legally inside its own half regardless of
    // target clamping above (defense in depth, matches movePlayerTo()).
    state.cpu.x = Math.max(WALL + MALLET_R, Math.min(W - WALL - MALLET_R, state.cpu.x));
    state.cpu.y = Math.max(WALL + MALLET_R, Math.min(CENTER_Y - MALLET_R, state.cpu.y));
  }

  // ---- Physics ----
  var FRICTION = 0.994;
  var MAX_PUCK_SPEED = 9;

  function reflectOffMallet(mallet, prevMalletX, prevMalletY) {
    var dx = state.puck.x - mallet.x;
    var dy = state.puck.y - mallet.y;
    var dist = Math.sqrt(dx * dx + dy * dy);
    var minDist = PUCK_R + MALLET_R;
    if (dist >= minDist || dist === 0) { return; }

    // Past this point a real collision is happening this frame — the
    // one natural hook point for the "mallet hit" sound + a small spark
    // burst at the contact point (30 Agu 2026 revamp). Doesn't touch any
    // of the physics below.
    sfx.malletHit();
    spawnBurst(state.puck.x, state.puck.y, '53,230,255', 6);

    var nx = dx / dist;
    var ny = dy / dist;

    // Push the puck fully outside the mallet first — without this,
    // a puck resting against a slow-moving mallet can get "stuck"
    // re-triggering the collision every frame.
    var overlap = minDist - dist;
    state.puck.x += nx * overlap;
    state.puck.y += ny * overlap;

    // Reflect puck velocity across the collision normal, then add a
    // slice of the mallet's own recent movement so a fast swing gives
    // the puck a real hit instead of a flat bounce.
    var dot = state.puck.vx * nx + state.puck.vy * ny;
    state.puck.vx = state.puck.vx - 2 * dot * nx;
    state.puck.vy = state.puck.vy - 2 * dot * ny;

    var malletVx = mallet.x - prevMalletX;
    var malletVy = mallet.y - prevMalletY;
    state.puck.vx += malletVx * 1.6 + nx * 1.5;
    state.puck.vy += malletVy * 1.6 + ny * 1.5;

    var speed = Math.sqrt(state.puck.vx * state.puck.vx + state.puck.vy * state.puck.vy);
    if (speed > MAX_PUCK_SPEED) {
      state.puck.vx = (state.puck.vx / speed) * MAX_PUCK_SPEED;
      state.puck.vy = (state.puck.vy / speed) * MAX_PUCK_SPEED;
    }
  }

  // Set inside updatePuck() below on any wall bounce (not a goal) — read
  // and cleared by loop() right after calling updatePuck(), so the sound
  // effect fires from one clear spot instead of duplicating the sfx.wallBounce()
  // call at all 4 bounce sites below. This is purely a same-frame event
  // flag, not a structural change to how/when bounces happen.
  var wallBounceThisFrame = false;

  /** @returns {'cpu'|'player'|null} which side just conceded a goal, or null if no goal this frame. */
  function updatePuck() {
    var p = state.puck;
    p.x += p.vx;
    p.y += p.vy;
    p.vx *= FRICTION;
    p.vy *= FRICTION;

    // Side walls.
    if (p.x - PUCK_R < WALL) {
      p.x = WALL + PUCK_R;
      p.vx = Math.abs(p.vx);
      wallBounceThisFrame = true;
    } else if (p.x + PUCK_R > W - WALL) {
      p.x = W - WALL - PUCK_R;
      p.vx = -Math.abs(p.vx);
      wallBounceThisFrame = true;
    }

    var goalMinX = W / 2 - GOAL_WIDTH / 2;
    var goalMaxX = W / 2 + GOAL_WIDTH / 2;
    var inGoalMouth = p.x > goalMinX && p.x < goalMaxX;

    // Top edge — either the AI's goal (puck scores for the player) or a
    // wall bounce if it's outside the goal opening. Bug fixed 30 Agu
    // 2026 (found in local testing before this ever shipped): the old
    // version clamped+bounced the puck back to y = WALL + PUCK_R on the
    // SAME frame it first crossed y < WALL, on every frame — including
    // inside the goal mouth — so the puck could never actually reach the
    // "did it cross y < 0" check below it; a goal was structurally
    // unreachable. Now: inside the goal mouth, the puck is let through
    // with NO clamp/bounce at all until it's fully past the line;
    // outside the goal mouth (hit the solid part of the wall), it
    // bounces exactly as before.
    if (p.y - PUCK_R < WALL) {
      if (inGoalMouth) {
        if (p.y < 0) {
          return 'player-scores';
        }
      } else {
        p.y = WALL + PUCK_R;
        p.vy = Math.abs(p.vy);
        wallBounceThisFrame = true;
      }
    }

    // Bottom edge — the player's own goal. Same fix, mirrored.
    if (p.y + PUCK_R > H - WALL) {
      if (inGoalMouth) {
        if (p.y > H) {
          return 'cpu-scores';
        }
      } else {
        p.y = H - WALL - PUCK_R;
        p.vy = -Math.abs(p.vy);
        wallBounceThisFrame = true;
      }
    }

    return null;
  }

  function endMatch(playerWon) {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    endTitleEl.textContent = playerWon ? 'Kamu Menang! ⚽' : 'Komputer Menang';
    endScoreEl.textContent = 'Skor akhir: ' + state.scorePlayer + ' - ' + state.scoreCpu;
    panelEnd.hidden = false;
    if (playerWon) { sfx.win(); } else { sfx.lose(); }
  }

  function loop() {
    if (!running || !state) { return; }

    var prevCpuX = state.cpu.px, prevCpuY = state.cpu.py;
    var prevPlayerX = state.player.px, prevPlayerY = state.player.py;

    updateAi();

    wallBounceThisFrame = false;
    var goalResult = updatePuck();
    if (wallBounceThisFrame) { sfx.wallBounce(); }

    state.trail.push({ x: state.puck.x, y: state.puck.y });
    if (state.trail.length > 8) { state.trail.shift(); }

    reflectOffMallet(state.cpu, prevCpuX, prevCpuY);
    reflectOffMallet(state.player, prevPlayerX, prevPlayerY);

    state.cpu.px = state.cpu.x; state.cpu.py = state.cpu.y;
    state.player.px = state.player.x; state.player.py = state.player.y;

    if (goalResult === 'player-scores') {
      state.scorePlayer++;
      scorePlayerEl.textContent = String(state.scorePlayer);
      sfx.goal();
      triggerGoalEffect(state.puck.x, 20, '255,176,59');
      if (state.scorePlayer >= WIN_SCORE) { updateParticles(); draw(); endMatch(true); return; }
      launchPuck(1);
    } else if (goalResult === 'cpu-scores') {
      state.scoreCpu++;
      scoreCpuEl.textContent = String(state.scoreCpu);
      sfx.goal();
      triggerGoalEffect(state.puck.x, H - 20, '255,61,154');
      if (state.scoreCpu >= WIN_SCORE) { updateParticles(); draw(); endMatch(false); return; }
      launchPuck(-1);
    }

    updateParticles();
    draw();
    rafId = requestAnimationFrame(loop);
  }

  // ---- Particle burst + goal flash (30 Agu 2026 revamp) ----
  // A tiny hand-rolled particle system — plain array of {x,y,vx,vy,life}
  // objects, updated/drawn alongside everything else in loop()/draw()
  // below. Only ever populated by spawnBurst() (goals + mallet hits), so
  // it costs nothing when idle. This is the "confetti/flash sekilas" the
  // brief asks for — deliberately NOT a general-purpose particle library.
  var particles = [];
  var flashAlpha = 0;
  var flashColor = '255,255,255';

  /** @param rgbTriplet e.g. '255,176,59' — comma-separated r,g,b (no rgb() wrapper). */
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

  function triggerGoalEffect(x, y, rgbTriplet) {
    spawnBurst(x, y, rgbTriplet, 22);
    flashAlpha = 0.35;
    flashColor = rgbTriplet;
  }

  function updateParticles() {
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      p.x += p.vx;
      p.y += p.vy;
      p.vx *= 0.96;
      p.vy *= 0.96;
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

  // ---- Rendering ----
  function setupCanvasResolution() {
    // Render at devicePixelRatio for crisp lines on retina/high-DPI
    // screens, while keeping the logical W x H coordinate space used by
    // every physics/AI calculation above untouched.
    var dpr = window.devicePixelRatio || 1;
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  setupCanvasResolution();
  window.addEventListener('resize', setupCanvasResolution);

  // Built once and reused every frame — creating a gradient is cheap but
  // not free, and this one never changes (fixed W/H), so there's no
  // reason to rebuild it 60x/sec.
  var rinkGradient = null;
  function getRinkGradient() {
    if (rinkGradient) { return rinkGradient; }
    // Radial vignette: lighter pitch-green center fading to a darker
    // edge — the "tekstur lapangan lebih hidup" the brief asks for,
    // done as a gradient instead of an image asset (keeps payload at
    // 0 extra bytes). Center is intentionally a touch warm/olive, not
    // pure green, so it doesn't read as a flat color swatch.
    var g = ctx.createRadialGradient(W / 2, H / 2, 40, W / 2, H / 2, H * 0.75);
    g.addColorStop(0, '#144a26');
    g.addColorStop(0.55, '#0b3d1f');
    g.addColorStop(1, '#062712');
    rinkGradient = g;
    return g;
  }

  function draw() {
    // Pitch background — textured green field (radial gradient + faint
    // mow-stripe lines) with center line + goal markers, the "football
    // packaging" the brief asks for instead of a generic grey air-hockey
    // table or (post-revamp) a flat single-color fill.
    ctx.fillStyle = getRinkGradient();
    ctx.fillRect(0, 0, W, H);

    // Faint alternating mow-stripe bands — a real pitch texture cue,
    // drawn as plain semi-transparent rects (cheap, no image).
    ctx.fillStyle = 'rgba(255,255,255,0.025)';
    for (var stripe = 0; stripe < H; stripe += 40) {
      if ((stripe / 40) % 2 === 0) { ctx.fillRect(WALL, stripe, W - WALL * 2, 40); }
    }

    ctx.strokeStyle = 'rgba(255,255,255,0.35)';
    ctx.lineWidth = 2;
    ctx.strokeRect(WALL, WALL, W - WALL * 2, H - WALL * 2);

    ctx.beginPath();
    ctx.moveTo(WALL, CENTER_Y);
    ctx.lineTo(W - WALL, CENTER_Y);
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(W / 2, CENTER_Y, 44, 0, Math.PI * 2);
    ctx.stroke();

    if (!state) { return; }

    var goalMinX = W / 2 - GOAL_WIDTH / 2;

    // Goals (top = CPU's goal / player scores here, bottom = player's
    // goal / CPU scores here) — drawn as a net-like rectangle.
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.fillRect(goalMinX, 0, GOAL_WIDTH, WALL + 2);
    ctx.fillRect(goalMinX, H - WALL - 2, GOAL_WIDTH, WALL + 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.6)';
    ctx.lineWidth = 3;
    ctx.strokeRect(goalMinX, 0, GOAL_WIDTH, WALL + 2);
    ctx.strokeRect(goalMinX, H - WALL - 2, GOAL_WIDTH, WALL + 2);

    drawTrail(state.trail);

    // Mallets — drawn as boots/feet (a rounded wedge) so they read as
    // "kicking" rather than a generic hockey paddle, per the brief.
    drawMallet(state.cpu.x, state.cpu.y, '#ff3d9a');
    drawMallet(state.player.x, state.player.y, '#35e6ff');

    // Puck — the ball.
    drawBall(state.puck.x, state.puck.y);

    // Goal-burst particles + flash sit on top of everything else.
    drawParticles();
  }

  /** Fading afterimage behind the puck — oldest point most transparent/smallest. */
  function drawTrail(trail) {
    for (var i = 0; i < trail.length; i++) {
      var t = trail[i];
      var age = (i + 1) / trail.length; // 0 (oldest) .. 1 (newest)
      ctx.beginPath();
      ctx.arc(t.x, t.y, PUCK_R * 0.7 * age, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(255,176,59,' + (age * 0.25) + ')';
      ctx.fill();
    }
  }

  function drawMallet(x, y, color) {
    // Outer glow halo — bigger/warmer than the MVP version per the
    // operator's reference (orange-yellow halo around moving pieces).
    ctx.beginPath();
    ctx.arc(x, y, MALLET_R * 1.15, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.18;
    ctx.shadowColor = color;
    ctx.shadowBlur = 22;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.globalAlpha = 1;

    ctx.beginPath();
    ctx.arc(x, y, MALLET_R, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.globalAlpha = 0.22;
    ctx.fill();
    ctx.globalAlpha = 1;

    ctx.beginPath();
    ctx.arc(x, y, MALLET_R * 0.55, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.shadowColor = color;
    ctx.shadowBlur = 18;
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.beginPath();
    ctx.arc(x, y, MALLET_R * 0.22, 0, Math.PI * 2);
    ctx.fillStyle = '#06080f';
    ctx.fill();
  }

  function drawBall(x, y) {
    // Warm halo behind the ball (per reference: orange/yellow glow
    // around the puck itself, on top of the white ball glow already
    // there in the MVP).
    ctx.beginPath();
    ctx.arc(x, y, PUCK_R * 1.6, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,176,59,0.28)';
    ctx.shadowColor = 'rgba(255,176,59,0.9)';
    ctx.shadowBlur = 16;
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.beginPath();
    ctx.arc(x, y, PUCK_R, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.shadowColor = 'rgba(255,255,255,0.9)';
    ctx.shadowBlur = 12;
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.strokeStyle = '#0b3d1f';
    ctx.lineWidth = 1.4;
    ctx.beginPath();
    ctx.moveTo(x - PUCK_R * 0.5, y - PUCK_R * 0.2);
    ctx.lineTo(x + PUCK_R * 0.5, y - PUCK_R * 0.2);
    ctx.lineTo(x, y + PUCK_R * 0.55);
    ctx.closePath();
    ctx.stroke();
  }

  // Idle preview render (table + placeholder positions) before a match
  // starts, so the canvas isn't a blank rect while ah-board is hidden.
  draw();
})();
