/**
 * Kuis Bola — Games Hub's third game (3 Sep 2026, brief "Games Hub —
 * game ketiga, Kuis Bola"). Vanilla JS, no engine/library, fully
 * self-contained (does not import/depend on air-hockey.js or
 * penalty-kick.js, even though it deliberately reuses the same
 * "synthesize a short tone via Web Audio" pattern — see initAudio()/
 * playTone() below, copied by design, not by reference).
 *
 * Unlike the first two games, this one has NO <canvas> and NO
 * requestAnimationFrame render loop for the game itself — a timed
 * multiple-choice quiz is a sequence of discrete states (show
 * question -> player picks or timer expires -> show feedback -> next
 * question), which is a much more natural fit for plain DOM/CSS
 * (question card, option buttons, a CSS-transitioned timer bar) than
 * a Canvas redraw loop. A single rAF loop IS used, but only to track
 * the countdown precisely for scoring purposes (see startTimer()) —
 * it never draws anything.
 *
 * Question bank: 100% hardcoded below (QUESTION_BANK), per operator's
 * explicit decision (see docs/DECISIONS.md, 3 Sep 2026 entry) — no
 * database table, no admin-panel CRUD, no API call. A future brief
 * may move this to an admin-managed source; this file deliberately
 * does NOT half-implement that (no fetch(), no partial DB scaffold).
 */
(function () {
  'use strict';

  var panelStart = document.getElementById('qb-panel-start');
  var panelEnd = document.getElementById('qb-panel-end');
  var boardEl = document.getElementById('qb-board');
  var startBtn = document.getElementById('qb-start-btn');
  var playAgainBtn = document.getElementById('qb-play-again-btn');
  var muteBtn = document.getElementById('qb-mute-btn');
  var difficultyBtns = Array.prototype.slice.call(document.querySelectorAll('.wpm-qb-difficulty__btn'));
  var difficultyBadge = document.getElementById('qb-difficulty-badge');
  var questionCountEl = document.getElementById('qb-question-count');
  var scoreEl = document.getElementById('qb-score');
  var timerFillEl = document.getElementById('qb-timer-fill');
  var timerSecondsEl = document.getElementById('qb-timer-seconds');
  var questionTextEl = document.getElementById('qb-question-text');
  var optionsEl = document.getElementById('qb-options');
  var endTitleEl = document.getElementById('qb-end-title');
  var endScoreEl = document.getElementById('qb-end-score');
  var endBreakdownEl = document.getElementById('qb-end-breakdown');

  // ---- Audio: short synthesized blips via Web Audio, same rationale
  // as air-hockey.js/penalty-kick.js (zero payload, zero licensing
  // risk, fits the neon "gaming vibe") — see docs/DECISIONS.md, 30 Agu
  // 2026 revamp entry, for the original writeup. Lazily created on the
  // first "Mulai Main" click (real user gesture), never at page load.
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

  // ---- Question bank (hardcoded, per operator decision — see file
  // docblock). Mix of: aturan dasar, sejarah Piala Dunia, klub besar,
  // pemain terkenal, dan sebagian kecil soal Indonesia (Liga 1/Timnas)
  // supaya relevan buat audiens Sagagoal tanpa jadi mayoritas niche.
  // correctIndex refers to the `options` array BEFORE shuffling —
  // pickQuestions()/shuffle happens at session-start time, see below.
  var QUESTION_BANK = [
    { q: 'Berapa jumlah pemain inti (di lapangan) satu tim sepak bola?', options: ['9', '10', '11', '12'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Berapa lama durasi normal satu babak pertandingan sepak bola?', options: ['30 menit', '45 menit', '60 menit', '90 menit'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Kartu apa yang diberikan wasit sebagai peringatan sebelum pemain dikeluarkan?', options: ['Kartu putih', 'Kartu kuning', 'Kartu biru', 'Kartu hijau'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Berapa kartu kuning yang membuat pemain otomatis terkena kartu merah?', options: ['1', '2', '3', '4'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Apa nama tendangan bebas tanpa halangan pemain lawan dari titik penalti?', options: ['Tendangan sudut', 'Tendangan penalti', 'Tendangan gawang', 'Lemparan ke dalam'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Pelanggaran apa yang membuat lawan mendapat lemparan ke dalam?', options: ['Bola keluar dari garis gawang', 'Bola keluar dari garis samping', 'Handball', 'Offside'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Apa istilah untuk posisi pemain yang berada lebih dekat ke gawang lawan dibanding bola dan pemain lawan terakhir?', options: ['Offside', 'Onside', 'Kickoff', 'Overlap'], correctIndex: 0, difficulty: 'medium' },
    { q: 'Berapa jarak titik penalti dari garis gawang (dalam meter, standar FIFA)?', options: ['9 meter', '11 meter', '13 meter', '16 meter'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Apa sebutan untuk babak tambahan waktu setelah 90 menit berakhir seri di pertandingan sistem gugur?', options: ['Injury time', 'Extra time', 'Stoppage time', 'Golden goal'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Siapa yang berhak mengganti pemain selama pertandingan berlangsung?', options: ['Kapten tim', 'Wasit', 'Pelatih', 'Wasit garis'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Berapa jumlah pemain minimal agar sebuah tim tetap boleh melanjutkan pertandingan?', options: ['5', '6', '7', '8'], correctIndex: 2, difficulty: 'hard' },
    { q: 'Apa nama garis tengah lapangan yang membagi lapangan jadi dua bagian sama besar?', options: ['Garis gawang', 'Garis tengah', 'Garis penalti', 'Garis sudut'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Negara mana yang menjadi juara Piala Dunia FIFA pertama tahun 1930?', options: ['Brasil', 'Uruguay', 'Argentina', 'Italia'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Negara mana yang paling banyak juara Piala Dunia FIFA (hingga 2026)?', options: ['Jerman', 'Argentina', 'Italia', 'Brasil'], correctIndex: 3, difficulty: 'easy' },
    { q: 'Piala Dunia FIFA 2022 diselenggarakan di negara mana?', options: ['Rusia', 'Qatar', 'Brasil', 'Jepang & Korea Selatan'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Siapa yang menjadi juara Piala Dunia FIFA 2022?', options: ['Prancis', 'Brasil', 'Argentina', 'Kroasia'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Piala Dunia FIFA 2018 diselenggarakan di negara mana?', options: ['Rusia', 'Jerman', 'Qatar', 'Afrika Selatan'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Negara mana yang menjadi tuan rumah Piala Dunia FIFA 2014?', options: ['Meksiko', 'Brasil', 'Chile', 'Kolombia'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Berapa tahun sekali Piala Dunia FIFA digelar?', options: ['2 tahun', '3 tahun', '4 tahun', '5 tahun'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Siapa pemain yang mencetak "Hand of God" di Piala Dunia 1986?', options: ['Pele', 'Diego Maradona', 'Zico', 'Michel Platini'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Piala Dunia FIFA 2026 diselenggarakan di negara mana (co-host, 3 negara)?', options: ['Spanyol, Portugal, Maroko', 'Amerika Serikat, Kanada, Meksiko', 'Inggris, Irlandia, Skotlandia', 'Arab Saudi, Mesir, Yordania'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Siapa yang menjadi juara Piala Dunia FIFA 2026?', options: ['Argentina', 'Prancis', 'Spanyol', 'Brasil'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Siapa pencetak gol terbanyak sepanjang sejarah Piala Dunia FIFA (hingga 2026)?', options: ['Miroslav Klose', 'Lionel Messi', 'Kylian Mbappe', 'Ronaldo Nazario'], correctIndex: 2, difficulty: 'hard' },
    { q: 'Trofi apa yang diberikan pada juara Piala Dunia FIFA?', options: ['Piala Champions', 'Piala Jules Rimet (nama trofi saat ini: FIFA World Cup Trophy)', 'Ballon d\'Or', 'Piala Toyota'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Manchester United bermain di liga mana?', options: ['La Liga', 'Serie A', 'Premier League', 'Bundesliga'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Real Madrid dan Barcelona bermain di liga mana?', options: ['Premier League', 'La Liga', 'Ligue 1', 'Serie A'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Bayern Munich adalah klub sepak bola dari negara mana?', options: ['Austria', 'Belanda', 'Jerman', 'Swiss'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Klub mana yang berjuluk "The Old Lady" di Serie A Italia?', options: ['AC Milan', 'Inter Milan', 'Juventus', 'AS Roma'], correctIndex: 2, difficulty: 'hard' },
    { q: 'Paris Saint-Germain (PSG) bermain di liga sepak bola negara mana?', options: ['Belgia', 'Prancis', 'Swiss', 'Monako'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Klub mana yang punya rekor juara Liga Champions UEFA terbanyak?', options: ['AC Milan', 'Liverpool', 'Bayern Munich', 'Real Madrid'], correctIndex: 3, difficulty: 'medium' },
    { q: 'Apa julukan Liverpool FC?', options: ['The Gunners', 'The Reds', 'The Blues', 'The Toffees'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Apa julukan Arsenal FC?', options: ['The Gunners', 'The Reds', 'The Citizens', 'The Hammers'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Apa julukan Manchester City?', options: ['The Gunners', 'The Toffees', 'The Citizens', 'The Reds'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Klub mana yang berbasis di kota Milan dan berjuluk "Nerazzurri"?', options: ['AC Milan', 'Inter Milan', 'Juventus', 'Napoli'], correctIndex: 1, difficulty: 'hard' },
    { q: 'AC Milan dan Inter Milan berbagi stadion yang bernama?', options: ['San Siro', 'Allianz Stadium', 'Stadio Olimpico', 'Camp Nou'], correctIndex: 0, difficulty: 'medium' },
    { q: 'Stadion Camp Nou adalah kandang dari klub?', options: ['Real Madrid', 'Atletico Madrid', 'Barcelona', 'Sevilla'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Cristiano Ronaldo berasal dari negara mana?', options: ['Spanyol', 'Brasil', 'Portugal', 'Argentina'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Lionel Messi berasal dari negara mana?', options: ['Argentina', 'Uruguay', 'Spanyol', 'Kolombia'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Siapa pemain yang identik dengan julukan "CR7"?', options: ['Cristiano Ronaldo', 'Robert Lewandowski', 'Karim Benzema', 'Neymar Jr'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Neymar Jr adalah pemain berasal dari negara?', options: ['Argentina', 'Portugal', 'Brasil', 'Uruguay'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Siapa legenda sepak bola Brasil yang memenangkan 3 Piala Dunia sebagai pemain?', options: ['Ronaldinho', 'Pele', 'Zico', 'Romario'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Penghargaan individu tahunan apa yang diberikan kepada pemain terbaik dunia oleh France Football?', options: ['FIFA Puskas Award', 'Ballon d\'Or', 'The Best FIFA Award', 'Golden Boot'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Penghargaan apa yang diberikan untuk pencetak gol terbanyak di sebuah kompetisi/musim?', options: ['Golden Ball', 'Golden Glove', 'Golden Boot', 'Golden Whistle'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Posisi pemain yang tugas utamanya menjaga gawang disebut?', options: ['Bek', 'Gelandang', 'Penjaga gawang (kiper)', 'Penyerang'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Apa sebutan untuk pemain yang mencetak 3 gol dalam satu pertandingan?', options: ['Double', 'Hat-trick', 'Triple kill', 'Brace'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Apa sebutan untuk pemain yang mencetak 2 gol dalam satu pertandingan?', options: ['Brace', 'Hat-trick', 'Double kick', 'Assist ganda'], correctIndex: 0, difficulty: 'hard' },
    { q: 'Zinedine Zidane pernah menjadi pelatih sukses di klub?', options: ['Barcelona', 'Real Madrid', 'PSG', 'Juventus'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Pep Guardiola saat ini (2026) melatih klub?', options: ['Bayern Munich', 'Barcelona', 'Manchester City', 'PSG'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Erling Haaland adalah penyerang berasal dari negara?', options: ['Swedia', 'Denmark', 'Norwegia', 'Finlandia'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Kylian Mbappe adalah pemain berasal dari negara?', options: ['Belgia', 'Prancis', 'Kamerun', 'Senegal'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Kompetisi klub antarnegara Eropa paling prestisius bernama?', options: ['UEFA Europa League', 'UEFA Champions League', 'UEFA Conference League', 'UEFA Super Cup'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Badan yang mengatur sepak bola dunia bernama?', options: ['UEFA', 'FIFA', 'IOC', 'CONCACAF'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Badan yang mengatur sepak bola di kawasan Eropa bernama?', options: ['FIFA', 'AFC', 'UEFA', 'CAF'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Badan yang mengatur sepak bola di kawasan Asia (termasuk Indonesia) bernama?', options: ['UEFA', 'CONMEBOL', 'AFC', 'CAF'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Apa nama liga sepak bola tertinggi di Indonesia saat ini?', options: ['Liga 2', 'Liga 1', 'Liga Super Indonesia', 'Divisi Utama'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Timnas Indonesia dijuluki dengan sebutan?', options: ['Garuda', 'Elang', 'Harimau', 'Singa'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Warna kebesaran jersey kandang Timnas Indonesia adalah?', options: ['Biru', 'Merah', 'Putih', 'Hijau'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Klub sepak bola mana yang berbasis di Jakarta dan salah satu klub tersukses di Liga 1?', options: ['Arema FC', 'Persib Bandung', 'Persija Jakarta', 'PSM Makassar'], correctIndex: 2, difficulty: 'medium' },
    { q: 'Persib adalah klub sepak bola yang berbasis di kota?', options: ['Surabaya', 'Bandung', 'Malang', 'Medan'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Kompetisi sepak bola tingkat Asia untuk klub, setara Liga Champions Eropa, bernama?', options: ['AFF Cup', 'AFC Champions League', 'Asian Cup', 'SEA Games'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Turnamen sepak bola antarnegara Asia Tenggara bernama?', options: ['AFC Cup', 'AFF Championship', 'Asian Games', 'Piala Presiden'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Siapa pemain naturalisasi kelahiran Belanda yang memperkuat lini pertahanan Timnas Indonesia era 2020-an?', options: ['Jordi Amat', 'Sandy Walsh', 'Shayne Pattynama', 'Jay Idzes'], correctIndex: 3, difficulty: 'hard' },
    { q: 'Apa nama stadion utama yang sering dipakai Timnas Indonesia bertanding di Jakarta?', options: ['Stadion Si Jalak Harupat', 'Gelora Bung Karno', 'Stadion Kanjuruhan', 'Stadion Manahan'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Berapa ukuran standar diameter bola sepak (dalam cm, kira-kira)?', options: ['sekitar 58 cm keliling wilayah bola / diameter ~22 cm', 'sekitar 68-70 cm keliling bola', 'sekitar 90 cm keliling bola', 'sekitar 45 cm keliling bola'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Apa istilah untuk babak adu penalti untuk menentukan pemenang setelah hasil seri?', options: ['Extra time', 'Golden goal', 'Adu penalti (penalty shoot-out)', 'Sudden death'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Wasit menggunakan apa untuk memulai/menghentikan pertandingan?', options: ['Bendera', 'Peluit', 'Bel', 'Terompet'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Petugas yang membantu wasit utama di sisi lapangan dan mengangkat bendera untuk offside disebut?', options: ['Wasit cadangan', 'Wasit garis (asisten wasit)', 'Manajer pertandingan', 'Pengawas pertandingan'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Teknologi yang digunakan wasit untuk meninjau ulang keputusan kontroversial disebut?', options: ['GPS Tracking', 'VAR (Video Assistant Referee)', 'Hawk-Eye Radar', 'Goal Sensor Chip'], correctIndex: 1, difficulty: 'easy' },
    { q: 'Formasi 4-4-2 dalam sepak bola merujuk pada susunan pemain apa?', options: ['4 kiper, 4 bek, 2 penyerang', '4 bek, 4 gelandang, 2 penyerang', '4 penyerang, 4 gelandang, 2 bek', '4 bek, 2 gelandang, 4 penyerang'], correctIndex: 1, difficulty: 'hard' },
    { q: 'David Beckham terkenal karena keahliannya dalam mengeksekusi tendangan?', options: ['Tendangan penalti', 'Tendangan bebas (free-kick)', 'Tendangan gawang', 'Tendangan sudut'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Siapa pelatih yang membawa Jerman Barat juara Piala Dunia 1990 setelah sebelumnya ikut juara sebagai pemain di 1974?', options: ['Franz Beckenbauer', 'Jurgen Klinsmann', 'Joachim Low', 'Otto Rehhagel'], correctIndex: 0, difficulty: 'hard' },
    { q: 'Siapa pelatih yang membawa Prancis juara Piala Dunia 2018 setelah sebelumnya juara sebagai pemain di 1998?', options: ['Zinedine Zidane', 'Didier Deschamps', 'Laurent Blanc', 'Raymond Domenech'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Selain Beckenbauer dan Deschamps, siapa satu lagi tokoh yang pernah juara Piala Dunia sebagai pemain sekaligus pelatih (Brasil, 1958/1962 sebagai pemain, 1970 sebagai pelatih)?', options: ['Mario Zagallo', 'Carlos Alberto Parreira', 'Vicente del Bosque', 'Aime Jacquet'], correctIndex: 0, difficulty: 'hard' },
    { q: 'Piala Dunia FIFA 2026 merupakan edisi ke berapa sejak pertama kali digelar tahun 1930?', options: ['21', '22', '23', '24'], correctIndex: 2, difficulty: 'hard' },
    { q: 'Piala Dunia FIFA 2026 pertama kali diikuti berapa negara peserta (perluasan dari 32 negara sebelumnya)?', options: ['32', '40', '48', '64'], correctIndex: 2, difficulty: 'hard' },
    { q: 'Siapa peraih Ballon d\'Or terbanyak sepanjang sejarah (hingga 2026), dengan 8 trofi?', options: ['Cristiano Ronaldo', 'Lionel Messi', 'Michel Platini', 'Johan Cruyff'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Siapa peraih Golden Boot (topskor turnamen) Piala Dunia FIFA 2026?', options: ['Lionel Messi', 'Kylian Mbappe', 'Lamine Yamal', 'Harry Kane'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Siapa peraih Golden Ball (pemain terbaik turnamen) Piala Dunia FIFA 2026?', options: ['Pedri', 'Rodri', 'Jude Bellingham', 'Vitinha'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Siapa peraih Golden Glove (kiper terbaik turnamen) Piala Dunia FIFA 2026?', options: ['Unai Simon', 'Emiliano Martinez', 'Thibaut Courtois', 'Alisson Becker'], correctIndex: 0, difficulty: 'hard' },
    { q: 'Siapa pencetak gol termuda dalam sejarah Piala Dunia FIFA (usia 17 tahun, di Piala Dunia 1958)?', options: ['Pele', 'Michael Owen', 'Kylian Mbappe', 'Ronaldo Nazario'], correctIndex: 0, difficulty: 'hard' },
    { q: 'Filosofi/sistem taktik yang dipopulerkan Timnas Belanda era Johan Cruyff dikenal dengan istilah?', options: ['Tiki-taka', 'Total Football', 'Catenaccio', 'Gegenpressing'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Berapa kali Argentina menjadi juara Piala Dunia FIFA hingga 2026?', options: ['2 kali', '3 kali', '4 kali', '5 kali'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Berapa kali Jerman (termasuk era Jerman Barat) menjadi juara Piala Dunia FIFA hingga 2026?', options: ['3 kali', '4 kali', '5 kali', '6 kali'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Siapa peraih Golden Boot Piala Dunia FIFA 2022 di Qatar (8 gol), meski timnya bukan juara?', options: ['Lionel Messi', 'Kylian Mbappe', 'Julian Alvarez', 'Olivier Giroud'], correctIndex: 1, difficulty: 'hard' },
    { q: 'Timnas Brasil dijuluki dengan sebutan?', options: ['Selecao', 'Die Mannschaft', 'La Albiceleste', 'Gli Azzurri'], correctIndex: 0, difficulty: 'medium' },
    { q: 'Timnas Argentina dijuluki dengan sebutan?', options: ['Selecao', 'La Albiceleste', 'Die Mannschaft', 'Oranje'], correctIndex: 1, difficulty: 'medium' },
    { q: 'Timnas Jerman dijuluki dengan sebutan?', options: ['Die Mannschaft', 'Gli Azzurri', 'La Albiceleste', 'Les Bleus'], correctIndex: 0, difficulty: 'medium' },
    { q: 'Timnas Prancis dijuluki dengan sebutan?', options: ['Les Bleus', 'Die Mannschaft', 'Oranje', 'Selecao'], correctIndex: 0, difficulty: 'medium' },
    { q: 'Ban yang dikenakan di lengan pemain untuk menandakan dia adalah kapten tim disebut?', options: ['Ban kapten', 'Ban lengan', 'Ban pelatih', 'Ban wasit'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Selain kiper di kotak penaltinya sendiri, bagian tubuh apa yang tidak boleh dipakai mengontrol bola secara sengaja?', options: ['Kaki', 'Kepala', 'Tangan', 'Dada'], correctIndex: 2, difficulty: 'easy' },
    { q: 'Berapa jumlah wasit utama yang memimpin jalannya satu pertandingan sepak bola resmi?', options: ['1', '2', '3', '4'], correctIndex: 0, difficulty: 'easy' },
    { q: 'Apa istilah untuk operan/umpan terakhir dari rekan setim yang langsung menghasilkan gol?', options: ['Assist', 'Cross', 'Through pass', 'Deflection'], correctIndex: 0, difficulty: 'easy' },
  ];

  var DIFFICULTY_TIMER_MS = { easy: 18000, medium: 12000, hard: 8000 };
  var QUESTIONS_PER_SESSION = 10;
  var BASE_POINTS = 1000;

  var selectedDifficulty = 'medium';
  var sessionQuestions = [];
  var currentIndex = 0;
  var score = 0;
  var correctCount = 0;
  var timerDurationMs = DIFFICULTY_TIMER_MS.medium;
  var timerRafId = null;
  var timerStartedAt = 0;
  var answered = false;
  var lastTickSecond = -1;

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
    }
    return a;
  }

  // Content-difficulty mix per level (3 Sep 2026 revision — Hard now also
  // pulls harder QUESTIONS, not just a shorter timer; see DECISIONS.md).
  // Each QUESTION_BANK item is tagged `difficulty: 'easy'|'medium'|'hard'`
  // by how commonly-known the fact is. Counts below are TARGETS, not
  // hard requirements — pickSessionQuestions() fills shortfalls from the
  // next-closest tier so a session never comes up short even if a pool
  // is thin, and never throws if counts don't divide evenly.
  var DIFFICULTY_MIX = {
    easy:   { easy: 7, medium: 3, hard: 0 },
    medium: { easy: 3, medium: 5, hard: 2 },
    hard:   { easy: 0, medium: 3, hard: 7 },
  };

  /**
   * Picks QUESTIONS_PER_SESSION questions from the bank according to the
   * difficulty mix for `level`, and independently shuffles each picked
   * question's own options (so the correct answer isn't always in the
   * same slot) — returns fresh objects with a re-derived correctIndex,
   * never mutating QUESTION_BANK.
   */
  function pickSessionQuestions(level) {
    var mix = DIFFICULTY_MIX[level] || DIFFICULTY_MIX.medium;
    var byTier = { easy: [], medium: [], hard: [] };
    QUESTION_BANK.forEach(function (item) {
      (byTier[item.difficulty] || byTier.medium).push(item);
    });
    Object.keys(byTier).forEach(function (tier) { byTier[tier] = shuffle(byTier[tier]); });

    var picked = [];
    var usedQ = {};
    var tierOrder = ['easy', 'medium', 'hard'];

    function takeFrom(tier, count) {
      var pool = byTier[tier];
      for (var i = 0; i < pool.length && count > 0; i++) {
        if (!usedQ[pool[i].q]) {
          picked.push(pool[i]);
          usedQ[pool[i].q] = true;
          count--;
        }
      }
      return count; // leftover that couldn't be filled from this tier
    }

    // First pass: try to satisfy the exact mix.
    var leftover = 0;
    tierOrder.forEach(function (tier) {
      leftover += takeFrom(tier, mix[tier]);
    });
    // Second pass: any shortfall (a thin pool) gets filled from
    // whichever tier still has unused questions, so a session is always
    // QUESTIONS_PER_SESSION long regardless of bank composition.
    if (leftover > 0) {
      tierOrder.forEach(function (tier) {
        leftover = takeFrom(tier, leftover);
      });
    }

    picked = shuffle(picked).slice(0, QUESTIONS_PER_SESSION);
    return picked.map(function (item) {
      var order = shuffle(item.options.map(function (_, i) { return i; }));
      var shuffledOptions = order.map(function (originalIdx) { return item.options[originalIdx]; });
      var newCorrectIndex = order.indexOf(item.correctIndex);
      return { q: item.q, options: shuffledOptions, correctIndex: newCorrectIndex };
    });
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
    stopTimer();
    panelStart.hidden = false;
    boardEl.hidden = true;
    panelEnd.hidden = true;
  }

  function startQuiz() {
    initAudio();
    sessionQuestions = pickSessionQuestions(selectedDifficulty);
    currentIndex = 0;
    score = 0;
    correctCount = 0;
    timerDurationMs = DIFFICULTY_TIMER_MS[selectedDifficulty] || DIFFICULTY_TIMER_MS.medium;
    difficultyBadge.textContent = selectedDifficulty.toUpperCase();
    scoreEl.textContent = '0';
    panelStart.hidden = true;
    panelEnd.hidden = true;
    boardEl.hidden = false;
    showQuestion();
  }

  function showQuestion() {
    answered = false;
    var item = sessionQuestions[currentIndex];
    questionCountEl.textContent = (currentIndex + 1) + '/' + QUESTIONS_PER_SESSION;
    questionTextEl.textContent = item.q;
    optionsEl.innerHTML = '';
    item.options.forEach(function (label, idx) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wpm-qb-option-btn';
      btn.textContent = label;
      btn.addEventListener('click', function () { handleAnswer(idx); });
      optionsEl.appendChild(btn);
    });
    startTimer();
  }

  /**
   * Timer bar is CSS-driven (width transition, see quiz-bola.css), but
   * the actual elapsed time used for SCORING is tracked here via a
   * lightweight rAF poll against performance.now() — this is the one
   * render-loop-shaped piece of code in this otherwise DOM-event-driven
   * game, and it only ever reads the clock/updates a class, never draws.
   */
  function startTimer() {
    stopTimer();
    timerFillEl.classList.remove('is-urgent');
    if (timerSecondsEl) { timerSecondsEl.classList.remove('is-urgent'); }
    // Force the bar to instantly snap to 100% (no transition), then on
    // the next frame set it to 0% so the width transition actually
    // animates the shrink over timerDurationMs.
    timerFillEl.style.transition = 'none';
    timerFillEl.style.width = '100%';
    timerStartedAt = performance.now();
    lastTickSecond = Math.ceil(timerDurationMs / 1000);
    // Numeric countdown (3 Sep 2026, operator request — "munculin aja
    // detiknya juga") — tracked separately from lastTickSecond (which
    // only fires the last-3-seconds tick sound) so the number updates
    // every second across the WHOLE countdown, not just near the end.
    var lastDisplayedSecond = -1;
    function updateSecondsDisplay(seconds) {
      if (!timerSecondsEl || seconds === lastDisplayedSecond) { return; }
      lastDisplayedSecond = seconds;
      timerSecondsEl.textContent = String(seconds);
    }
    updateSecondsDisplay(Math.ceil(timerDurationMs / 1000));
    requestAnimationFrame(function () {
      timerFillEl.style.transition = 'width linear ' + (timerDurationMs / 1000) + 's, background-color 0.2s ease';
      timerFillEl.style.width = '0%';
    });

    function poll() {
      var elapsed = performance.now() - timerStartedAt;
      var remaining = timerDurationMs - elapsed;
      var remainingSeconds = Math.ceil(remaining / 1000);
      updateSecondsDisplay(Math.max(0, remainingSeconds));
      if (remainingSeconds !== lastTickSecond && remainingSeconds >= 0 && remainingSeconds <= 3) {
        lastTickSecond = remainingSeconds;
        sfx.tick();
      }
      if (remaining <= timerDurationMs * 0.25) {
        timerFillEl.classList.add('is-urgent');
        if (timerSecondsEl) { timerSecondsEl.classList.add('is-urgent'); }
      }
      if (remaining <= 0) {
        handleTimeout();
        return;
      }
      timerRafId = requestAnimationFrame(poll);
    }
    timerRafId = requestAnimationFrame(poll);
  }

  function stopTimer() {
    if (timerRafId) { cancelAnimationFrame(timerRafId); timerRafId = null; }
  }

  function handleAnswer(pickedIndex) {
    if (answered) { return; }
    answered = true;
    stopTimer();
    var elapsed = performance.now() - timerStartedAt;
    var item = sessionQuestions[currentIndex];
    var isCorrect = pickedIndex === item.correctIndex;
    var optionButtons = optionsEl.querySelectorAll('.wpm-qb-option-btn');

    optionButtons.forEach(function (btn, idx) {
      btn.disabled = true;
      if (idx === item.correctIndex) { btn.classList.add('is-correct'); }
      else if (idx === pickedIndex) { btn.classList.add('is-wrong'); }
    });

    if (isCorrect) {
      // Speed-based score: full BASE_POINTS if answered instantly,
      // scaling down to 0 as elapsed time approaches the full timer
      // duration — simple linear falloff, easy to reason about and
      // still gives a strong "answer fast" incentive as the brief asked.
      var remainingFraction = Math.max(0, 1 - elapsed / timerDurationMs);
      var points = Math.round(BASE_POINTS * remainingFraction);
      score += points;
      correctCount++;
      scoreEl.textContent = String(score);
      sfx.correct();
    } else {
      sfx.wrong();
    }

    setTimeout(goToNextQuestion, 900);
  }

  function handleTimeout() {
    if (answered) { return; }
    answered = true;
    var item = sessionQuestions[currentIndex];
    var optionButtons = optionsEl.querySelectorAll('.wpm-qb-option-btn');
    optionButtons.forEach(function (btn, idx) {
      btn.disabled = true;
      if (idx === item.correctIndex) { btn.classList.add('is-correct'); }
    });
    sfx.timeout();
    setTimeout(goToNextQuestion, 900);
  }

  function goToNextQuestion() {
    currentIndex++;
    if (currentIndex >= sessionQuestions.length) {
      endQuiz();
      return;
    }
    showQuestion();
  }

  function endQuiz() {
    stopTimer();
    boardEl.hidden = true;
    panelEnd.hidden = false;
    endTitleEl.textContent = 'Selesai!';
    endScoreEl.textContent = String(score) + ' poin';
    endBreakdownEl.textContent = correctCount + ' dari ' + QUESTIONS_PER_SESSION + ' jawaban benar';
    sfx.finish();
  }

  startBtn.addEventListener('click', startQuiz);
  playAgainBtn.addEventListener('click', showStart);

  setMuteButtonUi();
})();
