/**
 * Slot Bola — Games Hub's fourth game (3 Sep 2026, brief "Games Hub —
 * game keempat, Slot Bola"). Vanilla JS, no engine/library, fully
 * self-contained (does not import/depend on air-hockey.js/
 * penalty-kick.js/quiz-bola.js, even though it reuses the same
 * "synthesize a short tone via Web Audio" pattern by design, not by
 * reference — see initAudio()/playTone() below).
 *
 * *** NO MONEY / CREDIT / COIN SYSTEM — READ BEFORE EDITING ***
 * Operator was asked explicitly whether this slot machine should use
 * spendable/rechargeable credits (i.e. read as gambling) or be purely
 * for entertainment with no financial representation at all, and chose
 * the latter. Concretely, that means in THIS file:
 *   - There is no "balance"/"credits"/"coins" variable anywhere. `score`
 *     below is a pure, uncapped, ever-increasing local counter (same
 *     abstraction level as air-hockey's/quiz-bola's score), never
 *     decremented, never a spend condition for spin().
 *   - spin() has NO precondition other than "not already mid-animation"
 *     (see `spinning` flag) — it is never blocked by score/credits, and
 *     never requires payment, waiting-for-refill, or ads to re-enable.
 *   - Nothing here reads/writes localStorage or a server endpoint for
 *     score persistence — reload resets to 0, same as every other game.
 * Do NOT introduce a spendable-currency concept into this file without
 * a fresh, explicit operator decision — see docs/DECISIONS.md (3 Sep
 * 2026 entry) for the full writeup of why this boundary exists.
 *
 * DOM/CSS-driven (no Canvas) — same reasoning as quiz-bola.js: a slot
 * machine's "reel spin" is a CSS transform transition (see spinReel()),
 * not a physics/collision loop, so Canvas + requestAnimationFrame would
 * be pure overhead here.
 *
 * *** v2 (5 Sep 2026, "Slot Bola v2 — Perdalam Gameplay") ***
 * Adds: 2 diagonal paylines, a win-streak score multiplier, a score-
 * based rank badge + rank-up toast, a 3-spin auto bonus round, near-
 * miss shake feedback, and 2 session-only achievement toasts. Every
 * one of these is a scoring/visual abstraction on top of the SAME
 * local `score` counter — none of them are a new currency, none of
 * them gate spin() behind anything, and none of them persist past a
 * reload. The "NO MONEY" constraint above is unchanged and still
 * governs every addition below (re-verified line by line, see
 * docs/DECISIONS.md, 5 Sep 2026 entry).
 */
(function () {
  'use strict';

  var scoreEl = document.getElementById('sb-score');
  var reelsEl = document.getElementById('sb-reels');
  var spinBtn = document.getElementById('sb-spin-btn');
  var resultEl = document.getElementById('sb-result');
  var muteBtn = document.getElementById('sb-mute-btn');
  var paytableToggle = document.getElementById('sb-paytable-toggle');
  var paytableEl = document.getElementById('sb-paytable');
  var rankBadgeEl = document.getElementById('sb-rank-badge');
  var streakBadgeEl = document.getElementById('sb-streak-badge');
  var streakCountEl = document.getElementById('sb-streak-count');
  var streakMultEl = document.getElementById('sb-streak-mult');
  var bonusBannerEl = document.getElementById('sb-bonus-banner');
  var toastWrapEl = document.getElementById('sb-toast-wrap');
  var strips = [
    document.getElementById('sb-strip-0'),
    document.getElementById('sb-strip-1'),
    document.getElementById('sb-strip-2'),
  ];
  var reelEls = Array.prototype.slice.call(document.querySelectorAll('.wpm-sb-reel'));
  var paylineEls = [
    document.getElementById('sb-payline-0'),
    document.getElementById('sb-payline-1'),
    document.getElementById('sb-payline-2'),
    document.getElementById('sb-payline-3'),
    document.getElementById('sb-payline-4'),
  ];
  // Each payline as [reel, row] cells, in reel-0/1/2 order — index into
  // this array lines up 1:1 with `paylineEls` above (0-2 horizontal,
  // 3-4 diagonal). Every line touches each reel exactly once, so cell
  // index === reel index for all 5 lines (used by the near-miss shake
  // to know which single reel to shake).
  var PAYLINES = [
    { cells: [[0, 0], [1, 0], [2, 0]], diagonal: false },
    { cells: [[0, 1], [1, 1], [2, 1]], diagonal: false },
    { cells: [[0, 2], [1, 2], [2, 2]], diagonal: false },
    { cells: [[0, 0], [1, 1], [2, 2]], diagonal: true },
    { cells: [[0, 2], [1, 1], [2, 0]], diagonal: true },
  ];

  /**
   * Sizes/rotates the 2 diagonal payline bars to actually span corner
   * to corner of the reels grid — the grid isn't a perfect square at
   * every breakpoint, so a fixed CSS angle would drift off the real
   * diagonal. Called on load and on resize (debounced via rAF so a
   * drag-resize doesn't spam layout reads).
   */
  function layoutDiagonalPaylines() {
    if (!reelsEl) { return; }
    var w = reelsEl.clientWidth;
    var h = reelsEl.clientHeight;
    if (!w || !h) { return; }
    var diagLen = Math.ceil(Math.sqrt(w * w + h * h));
    var angleDeg = Math.atan2(h, w) * (180 / Math.PI);
    var downEl = paylineEls[3]; // top-left -> bottom-right
    var upEl = paylineEls[4]; // bottom-left -> top-right
    if (downEl) {
      downEl.style.width = diagLen + 'px';
      downEl.style.transform = 'translate(-50%, -50%) rotate(' + angleDeg + 'deg)';
    }
    if (upEl) {
      upEl.style.width = diagLen + 'px';
      upEl.style.transform = 'translate(-50%, -50%) rotate(' + (-angleDeg) + 'deg)';
    }
  }
  var diagLayoutRaf = null;
  window.addEventListener('resize', function () {
    if (diagLayoutRaf) { cancelAnimationFrame(diagLayoutRaf); }
    diagLayoutRaf = requestAnimationFrame(layoutDiagonalPaylines);
  });

  // ---- Audio: short synthesized blips, same pattern/rationale as the
  // other 3 games (zero payload, zero licensing risk). Lazily created
  // on the first "Spin" click (real user gesture).
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
    spin: function () { playTone([200, 500], 0.18, 'square', 0.05, 0); },
    reelStop: function () { playTone(440, 0.05, 'square', 0.05, 0); },
    win: function () {
      playTone(523.25, 0.11, 'triangle', 0.12, 0);
      playTone(659.25, 0.11, 'triangle', 0.12, 0.1);
      playTone(783.99, 0.11, 'triangle', 0.12, 0.2);
      playTone(1046.5, 0.22, 'triangle', 0.13, 0.3);
    },
    jackpot: function () {
      playTone(523.25, 0.13, 'triangle', 0.13, 0);
      playTone(659.25, 0.13, 'triangle', 0.13, 0.11);
      playTone(783.99, 0.13, 'triangle', 0.13, 0.22);
      playTone(1046.5, 0.13, 'triangle', 0.13, 0.33);
      playTone(1318.5, 0.3, 'triangle', 0.14, 0.44);
    },
    noWin: function () { playTone(196, 0.16, 'sawtooth', 0.06, 0); },
    // v2 additions below — same envelope-tone technique, no new assets.
    nearMiss: function () { playTone([300, 240], 0.1, 'square', 0.05, 0); },
    bonusStart: function () {
      playTone(392, 0.1, 'square', 0.1, 0);
      playTone(523.25, 0.1, 'square', 0.1, 0.1);
      playTone(659.25, 0.16, 'square', 0.1, 0.2);
    },
    rankUp: function () {
      playTone(659.25, 0.09, 'triangle', 0.1, 0);
      playTone(880, 0.18, 'triangle', 0.11, 0.09);
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

  // ---- Symbols — 7 total, weighted so common ones (kartu kuning,
  // sepatu) show up a lot more than rare ones (trofi, wild bintang).
  // `weight` is relative, not a percentage (see weightedPick() below).
  // `points` is a LOCAL, in-session score value only — never a
  // currency, never redeemable, never persisted server-side (see file
  // docblock's "NO MONEY" note).
  var SYMBOLS = [
    { emoji: '🟨', name: 'Kartu Kuning', points: 50, weight: 30, wild: false },
    { emoji: '👟', name: 'Sepatu Bola', points: 80, weight: 24, wild: false },
    { emoji: '🟥', name: 'Kartu Merah', points: 120, weight: 18, wild: false },
    { emoji: '🥅', name: 'Gawang', points: 200, weight: 13, wild: false },
    { emoji: '⚽', name: 'Bola', points: 350, weight: 8, wild: false },
    { emoji: '🏆', name: 'Trofi', points: 700, weight: 4, wild: false },
    { emoji: '⭐', name: 'Wild', points: 0, weight: 3, wild: true },
    // v2 (5 Sep 2026) — bonus-round trigger symbol. `points: 0` is
    // deliberate: landing this on a payline is a "win" (all 3 match)
    // worth 0 local points on its own, its real reward is the bonus
    // round it triggers (see checkBonusTrigger()/startBonusRound()),
    // NOT any kind of credit — still just the local `score` counter.
    { emoji: '🎫', name: 'Tiket Bonus', points: 0, weight: 2, wild: false },
  ];
  var JACKPOT_POINTS = 1500; // 3x wild on one payline — the rarest possible line

  /**
   * v2: two weighted tables instead of one — NORMAL_TABLE is the
   * original odds, BONUS_TABLE (used only while `inBonusRound`) roughly
   * doubles the weight of the 3 highest-value non-ticket symbols (Bola/
   * Trofi/Wild) so the free bonus spins feel noticeably more generous.
   * BONUS_TABLE holds its own copies of the symbol objects (not the
   * same references as SYMBOLS) purely so weight can differ without
   * mutating the base table — evaluateLine()/paytable compare by
   * `.name`, never by object identity, so this is safe.
   */
  function buildWeightedTable(list) {
    return { list: list, total: list.reduce(function (sum, s) { return sum + s.weight; }, 0) };
  }
  var NORMAL_TABLE = buildWeightedTable(SYMBOLS);
  var BONUS_BOOST_NAMES = { 'Bola': true, 'Trofi': true, 'Wild': true };
  var BONUS_TABLE = buildWeightedTable(SYMBOLS.map(function (s) {
    var boosted = !!BONUS_BOOST_NAMES[s.name];
    return { emoji: s.emoji, name: s.name, points: s.points, weight: boosted ? s.weight * 2 : s.weight, wild: s.wild };
  }));

  function weightedPickFrom(table) {
    var r = Math.random() * table.total;
    for (var i = 0; i < table.list.length; i++) {
      r -= table.list[i].weight;
      if (r <= 0) { return table.list[i]; }
    }
    return table.list[table.list.length - 1];
  }
  function weightedPick() {
    return weightedPickFrom(inBonusRound ? BONUS_TABLE : NORMAL_TABLE);
  }

  // ---- Rank ladder (v2) — purely a label derived from the local
  // `score` counter, same abstraction level as the score itself. Never
  // unlocks anything, never gates spin(), never persisted.
  var RANKS = [
    { min: 0, name: 'Pemain Amatir' },
    { min: 2000, name: 'Rookie' },
    { min: 6000, name: 'Semi Pro' },
    { min: 15000, name: 'Pro' },
    { min: 35000, name: 'Legend' },
  ];
  function rankForScore(s) {
    var current = RANKS[0];
    for (var i = 0; i < RANKS.length; i++) {
      if (s >= RANKS[i].min) { current = RANKS[i]; }
    }
    return current;
  }

  var score = 0;
  var spinning = false;
  var FILLER_COUNT = 16;
  var REEL_DURATIONS_MS = [900, 1250, 1650]; // cascading stop, reel 0 first

  // ---- v2 gameplay state ----
  var winStreak = 0;
  var currentRankIndex = 0; // index into RANKS, tracked to detect rank-ups
  var inBonusRound = false;
  var bonusSpinsRemaining = 0;
  var achievedStreak3 = false; // session-only flags, never persisted
  var achievedFirstJackpot = false;

  function streakMultiplier(streak) {
    if (streak >= 4) { return 2; }
    if (streak === 3) { return 1.5; }
    if (streak === 2) { return 1.2; }
    return 1; // streak 0 or 1 — no bonus yet
  }

  function updateStreakBadge() {
    if (winStreak <= 0) {
      streakBadgeEl.hidden = true;
      return;
    }
    var mult = streakMultiplier(winStreak);
    streakCountEl.textContent = 'x' + winStreak;
    streakMultEl.textContent = mult > 1 ? '(poin x' + mult + ')' : '';
    streakBadgeEl.hidden = false;
  }

  function updateRankBadge(justScored) {
    var rank = rankForScore(score);
    var newIndex = RANKS.indexOf(rank);
    rankBadgeEl.textContent = rank.name;
    if (justScored && newIndex > currentRankIndex) {
      currentRankIndex = newIndex;
      rankBadgeEl.classList.remove('is-leveling-up');
      void rankBadgeEl.offsetWidth; // restart the pulse animation
      rankBadgeEl.classList.add('is-leveling-up');
      sfx.rankUp();
      showToast('🎖️ Naik rank: ' + rank.name + '!');
    } else {
      currentRankIndex = newIndex;
    }
  }

  // ---- Toasts (v2) — small, auto-dismissing notices for rank-ups and
  // session achievements. Stack in #sb-toast-wrap, oldest on top.
  function showToast(text) {
    if (!toastWrapEl) { return; }
    var el = document.createElement('div');
    el.className = 'wpm-sb-toast';
    el.textContent = text;
    toastWrapEl.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-leaving');
      setTimeout(function () { el.remove(); }, 320);
    }, 2600);
  }

  function showBonusBanner(show) {
    bonusBannerEl.hidden = !show;
  }

  function buildPaytable() {
    paytableEl.innerHTML = '';
    // Rarest/highest-value first reads more naturally as a "paytable".
    SYMBOLS.slice().reverse().forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'wpm-sb-paytable__row';
      var left = document.createElement('span');
      left.textContent = s.emoji;
      var right = document.createElement('span');
      right.textContent = s.wild
        ? 'Wild — cocok apa saja'
        : (s.name === 'Tiket Bonus' ? '3 sebaris = Bonus Round!' : (s.points + ' poin / baris'));
      row.appendChild(left);
      row.appendChild(right);
      paytableEl.appendChild(row);
    });
    var jackpotRow = document.createElement('div');
    jackpotRow.className = 'wpm-sb-paytable__row';
    var jpLeft = document.createElement('span');
    jpLeft.textContent = '⭐⭐⭐';
    var jpRight = document.createElement('span');
    jpRight.textContent = JACKPOT_POINTS + ' poin (jackpot)';
    jackpotRow.appendChild(jpLeft);
    jackpotRow.appendChild(jpRight);
    paytableEl.appendChild(jackpotRow);
  }
  buildPaytable();

  if (paytableToggle) {
    paytableToggle.addEventListener('click', function () {
      var willShow = paytableEl.hidden;
      paytableEl.hidden = !willShow;
      paytableToggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
      paytableToggle.textContent = willShow ? 'Sembunyikan tabel poin' : 'Lihat tabel poin';
    });
  }

  /**
   * Renders one reel's filler + final symbols into its strip, resets
   * the strip to an un-transitioned translateY(0), then (next frame)
   * animates it to the offset that lands the final 3 symbols exactly
   * in the 3-cell viewport — cellHeight is measured from the actual
   * rendered cell rather than hardcoded, so it stays correct across
   * the CSS file's mobile/desktop cell-size breakpoint.
   */
  function spinReel(reelIndex, finalSymbols, durationMs, onDone) {
    var strip = strips[reelIndex];
    var fragment = document.createDocumentFragment();
    var total = FILLER_COUNT + finalSymbols.length;
    for (var i = 0; i < FILLER_COUNT; i++) {
      fragment.appendChild(makeCell(weightedPick()));
    }
    finalSymbols.forEach(function (s) { fragment.appendChild(makeCell(s)); });

    strip.style.transition = 'none';
    strip.style.transform = 'translateY(0px)';
    strip.innerHTML = '';
    strip.appendChild(fragment);
    // Force reflow so the "transition:none" reset above is actually
    // applied before we read cell height / re-enable the transition.
    void strip.offsetHeight;

    // Math.ceil (not raw float) — getBoundingClientRect() can return a
    // sub-pixel value depending on device pixel ratio, and undershooting
    // the true cell height here left a 1-3px sliver of the row above
    // peeking under the reel's overflow:hidden on some viewports.
    var cellHeight = strip.firstElementChild ? Math.ceil(strip.firstElementChild.getBoundingClientRect().height) : 76;
    var targetY = -(FILLER_COUNT * cellHeight);

    requestAnimationFrame(function () {
      strip.style.transition = 'transform ' + (durationMs / 1000) + 's cubic-bezier(0.15, 0.7, 0.25, 1)';
      strip.style.transform = 'translateY(' + targetY + 'px)';
    });

    setTimeout(function () {
      sfx.reelStop();
      onDone();
    }, durationMs + 30);

    void total; // total kept for clarity/debugging, not otherwise used
  }

  function makeCell(symbol) {
    var cell = document.createElement('div');
    cell.className = 'wpm-sb-reel__cell';
    cell.textContent = symbol.emoji;
    return cell;
  }

  function clearPaylineHighlights() {
    paylineEls.forEach(function (el) { el.classList.remove('is-win'); });
  }

  /** A line wins if every NON-wild symbol in it is the same symbol —
   * wilds fill in for anything. 3 wilds = jackpot. Returns
   * {win, points, label, nearMiss}. `nearMiss` (v2) is only ever true
   * when `win` is false: exactly 2 of the 3 raw symbols share a name
   * (any pairing that includes a wild would already have won above, so
   * reaching this check means the pair is 2 genuine non-wild symbols +
   * 1 different one) — purely a feedback signal, never affects scoring. */
  function evaluateLine(lineSymbols) {
    var nonWild = lineSymbols.filter(function (s) { return !s.wild; });
    if (nonWild.length === 0) {
      return { win: true, points: JACKPOT_POINTS, label: 'JACKPOT ⭐⭐⭐', nearMiss: false };
    }
    var first = nonWild[0];
    var allSame = nonWild.every(function (s) { return s.name === first.name; });
    if (allSame) {
      return { win: true, points: first.points, label: lineSymbols.map(function (s) { return s.emoji; }).join(''), nearMiss: false };
    }
    var counts = {};
    lineSymbols.forEach(function (s) { counts[s.name] = (counts[s.name] || 0) + 1; });
    var distinctNames = Object.keys(counts);
    var hasPair = distinctNames.length === 2 && distinctNames.some(function (n) { return counts[n] === 2; });
    return { win: false, points: 0, label: '', nearMiss: hasPair };
  }

  /** v2: bonus round trigger — 🎫 on all 3 reels in the SAME straight
   * row (rows 0/1/2 only, not the diagonals — kept simple/literal per
   * brief). Returns the row index (0-2) of the first match, or -1. */
  function checkBonusTrigger(reelResults) {
    for (var rowIndex = 0; rowIndex < 3; rowIndex++) {
      var allTicket = [0, 1, 2].every(function (reelIndex) {
        return reelResults[reelIndex][rowIndex].name === 'Tiket Bonus';
      });
      if (allTicket) { return rowIndex; }
    }
    return -1;
  }

  function startBonusRound() {
    inBonusRound = true;
    bonusSpinsRemaining = 3;
    showBonusBanner(true);
    sfx.bonusStart();
    spinBtn.disabled = true;
    setTimeout(function () {
      bonusSpinsRemaining--;
      spin();
    }, 1000);
  }

  function endBonusRound() {
    inBonusRound = false;
    bonusSpinsRemaining = 0;
    showBonusBanner(false);
    spinBtn.disabled = false;
  }

  function triggerNearMissShake(reelIndex) {
    var el = reelEls[reelIndex];
    if (!el) { return; }
    el.classList.remove('is-near-miss');
    void el.offsetWidth;
    el.classList.add('is-near-miss');
    setTimeout(function () { el.classList.remove('is-near-miss'); }, 450);
  }

  function spin() {
    if (spinning) { return; }
    spinning = true;
    initAudio();
    spinBtn.disabled = true;
    clearPaylineHighlights();
    resultEl.textContent = ' ';
    sfx.spin();

    // Pre-roll all 9 results up front (3 reels x 3 rows) — the reel
    // animation is purely cosmetic, the outcome is decided here.
    var reelResults = [0, 1, 2].map(function () {
      return [weightedPick(), weightedPick(), weightedPick()];
    });

    var reelsFinished = 0;
    [0, 1, 2].forEach(function (i) {
      spinReel(i, reelResults[i], REEL_DURATIONS_MS[i], function () {
        reelsFinished++;
        if (reelsFinished === 3) {
          resolveSpin(reelResults);
        }
      });
    });
  }

  function resolveSpin(reelResults) {
    // PAYLINES[i].cells -> build each of the 5 lines' 3 symbols by
    // reading straight from reelResults (cell index === reel index for
    // every line, see PAYLINES' own comment above).
    var lines = PAYLINES.map(function (line) {
      return line.cells.map(function (cell) { return reelResults[cell[0]][cell[1]]; });
    });
    var winningIndexes = [];
    var totalPoints = 0;
    var isJackpot = false;
    var nearMissReel = -1; // set to the first near-miss line's "odd one out" reel, if any
    lines.forEach(function (lineSymbols, lineIndex) {
      var outcome = evaluateLine(lineSymbols);
      if (outcome.win) {
        winningIndexes.push(lineIndex);
        totalPoints += outcome.points;
        if (outcome.points === JACKPOT_POINTS) { isJackpot = true; }
        paylineEls[lineIndex].classList.add('is-win');
      } else if (outcome.nearMiss && nearMissReel === -1) {
        // Find the single symbol (by cell position, which === reel
        // index) that broke the match, to shake just that reel.
        var counts = {};
        lineSymbols.forEach(function (s) { counts[s.name] = (counts[s.name] || 0) + 1; });
        var oddPos = lineSymbols.findIndex(function (s) { return counts[s.name] === 1; });
        if (oddPos !== -1) { nearMissReel = oddPos; }
      }
    });

    // Win-streak + multiplier (v2) — computed BEFORE adding to score,
    // per brief: this spin's own win counts toward its own streak
    // tier (streak 1 = the first win = still x1, no bonus yet).
    var multiplier = 1;
    var finalPoints = 0;
    if (totalPoints > 0) {
      winStreak++;
      multiplier = streakMultiplier(winStreak);
      finalPoints = Math.round(totalPoints * multiplier);
      score += finalPoints;
      scoreEl.textContent = String(score);
    } else {
      winStreak = 0;
    }
    updateStreakBadge();
    updateRankBadge(finalPoints > 0);

    if (!achievedStreak3 && winStreak === 3) {
      achievedStreak3 = true;
      showToast('🔥 Menang 3x Beruntun!');
    }
    if (!achievedFirstJackpot && isJackpot) {
      achievedFirstJackpot = true;
      showToast('🎰 Jackpot Pertama!');
    }

    var bonusRow = checkBonusTrigger(reelResults);
    var bonusJustTriggered = bonusRow !== -1 && !inBonusRound;

    if (finalPoints > 0) {
      var hasHorizontal = winningIndexes.some(function (i) { return !PAYLINES[i].diagonal; });
      var hasDiagonal = winningIndexes.some(function (i) { return PAYLINES[i].diagonal; });
      var kindLabel = hasHorizontal && hasDiagonal ? 'horizontal + diagonal' : (hasDiagonal ? 'diagonal' : 'horizontal');
      var multLabel = multiplier > 1 ? ' (x' + multiplier + ' streak)' : '';
      resultEl.textContent = (isJackpot ? '🎉 JACKPOT! ' : 'Menang! ') + winningIndexes.length + ' baris cocok (' + kindLabel + '), +' + finalPoints + ' poin' + multLabel;
      if (isJackpot) { sfx.jackpot(); } else { sfx.win(); }
    } else if (bonusJustTriggered) {
      resultEl.textContent = '🎫 3 Tiket Bonus! Bonus round dimulai...';
    } else {
      resultEl.textContent = 'Belum beruntung, coba lagi!';
      if (nearMissReel !== -1) {
        sfx.nearMiss();
        triggerNearMissShake(nearMissReel);
      } else {
        sfx.noWin();
      }
    }

    // v2: chain bonus-round auto-spins, or start a new bonus round.
    // spin() already set `spinning = false` is NOT true yet at this
    // point — do it here so a chained spin() call below is allowed in.
    spinning = false;
    if (inBonusRound) {
      if (bonusSpinsRemaining > 0) {
        spinBtn.disabled = true;
        bonusSpinsRemaining--;
        setTimeout(function () { spin(); }, 900);
        return;
      }
      endBonusRound();
      return;
    }
    if (bonusJustTriggered) {
      startBonusRound();
      return;
    }
    spinBtn.disabled = false;
  }

  spinBtn.addEventListener('click', spin);

  setMuteButtonUi();
  updateRankBadge(false);
  layoutDiagonalPaylines();
})();
