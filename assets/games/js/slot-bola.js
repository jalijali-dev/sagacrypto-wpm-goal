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
  var strips = [
    document.getElementById('sb-strip-0'),
    document.getElementById('sb-strip-1'),
    document.getElementById('sb-strip-2'),
  ];
  var paylineEls = [
    document.getElementById('sb-payline-0'),
    document.getElementById('sb-payline-1'),
    document.getElementById('sb-payline-2'),
  ];

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
  ];
  var JACKPOT_POINTS = 1500; // 3x wild on one payline — the rarest possible line

  var totalWeight = SYMBOLS.reduce(function (sum, s) { return sum + s.weight; }, 0);

  function weightedPick() {
    var r = Math.random() * totalWeight;
    for (var i = 0; i < SYMBOLS.length; i++) {
      r -= SYMBOLS[i].weight;
      if (r <= 0) { return SYMBOLS[i]; }
    }
    return SYMBOLS[SYMBOLS.length - 1];
  }

  var score = 0;
  var spinning = false;
  var FILLER_COUNT = 16;
  var REEL_DURATIONS_MS = [900, 1250, 1650]; // cascading stop, reel 0 first

  function buildPaytable() {
    paytableEl.innerHTML = '';
    // Rarest/highest-value first reads more naturally as a "paytable".
    SYMBOLS.slice().reverse().forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'wpm-sb-paytable__row';
      var left = document.createElement('span');
      left.textContent = s.emoji;
      var right = document.createElement('span');
      right.textContent = s.wild ? 'Wild — cocok apa saja' : (s.points + ' poin / baris');
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

  /** A row wins if every NON-wild symbol in it is the same symbol —
   * wilds fill in for anything. 3 wilds = jackpot. Returns
   * {win, points, label} for that single row. */
  function evaluateRow(rowSymbols) {
    var nonWild = rowSymbols.filter(function (s) { return !s.wild; });
    if (nonWild.length === 0) {
      return { win: true, points: JACKPOT_POINTS, label: 'JACKPOT ⭐⭐⭐' };
    }
    var first = nonWild[0];
    var allSame = nonWild.every(function (s) { return s.name === first.name; });
    if (allSame) {
      return { win: true, points: first.points, label: rowSymbols.map(function (s) { return s.emoji; }).join('') };
    }
    return { win: false, points: 0, label: '' };
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
    // reelResults[reelIndex][rowIndex] -> build rows across reels.
    var rows = [0, 1, 2].map(function (rowIndex) {
      return [reelResults[0][rowIndex], reelResults[1][rowIndex], reelResults[2][rowIndex]];
    });
    var winningRows = [];
    var totalPoints = 0;
    var isJackpot = false;
    rows.forEach(function (row, rowIndex) {
      var outcome = evaluateRow(row);
      if (outcome.win) {
        winningRows.push(rowIndex);
        totalPoints += outcome.points;
        if (outcome.points === JACKPOT_POINTS) { isJackpot = true; }
        paylineEls[rowIndex].classList.add('is-win');
      }
    });

    if (totalPoints > 0) {
      score += totalPoints;
      scoreEl.textContent = String(score);
      var lineWord = winningRows.length > 1 ? 'baris' : 'baris';
      resultEl.textContent = (isJackpot ? '🎉 JACKPOT! ' : 'Menang! ') + winningRows.length + ' ' + lineWord + ' cocok, +' + totalPoints + ' poin';
      if (isJackpot) { sfx.jackpot(); } else { sfx.win(); }
    } else {
      resultEl.textContent = 'Belum beruntung, coba lagi!';
      sfx.noWin();
    }

    spinning = false;
    spinBtn.disabled = false;
  }

  spinBtn.addEventListener('click', spin);

  setMuteButtonUi();
})();
