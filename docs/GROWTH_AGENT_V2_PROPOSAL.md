# Growth Agent v2 — Roadmap (disetujui jadi prioritas kerja, 1 Agu 2026)

> **Status dokumen: DISETUJUI jadi antrian kerja** — ringkasannya sudah
> dipindahkan ke `docs/ROADMAP.md` § Next (lihat entri "Growth Agent v2").
> Dokumen ini tetap jadi rujukan detail teknis lengkap tiap item (alasan,
> skema DB yang relevan, urutan fase) — `ROADMAP.md` sengaja cuma nyimpen
> ringkasan + link balik ke sini, bukan duplikat semua detail.
>
> Dibuat: 1 Agustus 2026, dipicu dari perbandingan sama diagram workflow
> growth automation "Val's Cake" (referensi eksternal) dan pertanyaan:
> workflow apa yang paling efektif buat naikin artikel & SEO Sagagoal ke
> page 1 Google. Belum ada satu item pun yang mulai dikerjakan — status
> "disetujui" di sini artinya "masuk antrian resmi", bukan "sudah jadi".

---

## 1. Kondisi sekarang vs gap

Growth Agent Sagagoal saat ini (lihat `docs/GROWTH_AGENT_SUMMARY.md`)
sudah kuat di bagian "otak": deteksi peluang SEO (Opportunity Engine),
AI bikin rekomendasi (3 job type), Action Queue + approval manusia, draft
artikel otomatis dari ide yang di-approve, indexing check, agent memory,
feedback loop before/after, deteksi cannibalization + content decay, dan
topic cluster/content conflict detection. Prinsip keamanan ("AI gak
approve, AI gak publish") dijaga penuh di semua fitur itu.

Tiga gap struktural terbesar dibanding workflow ideal (dan dibanding
referensi "Val's Cake"):

1. **Gak ada trigger otomatis mandiri.** Semua proses "lazy" — cuma jalan
   kalau ada admin yang kebetulan buka halaman `growth-agent.php`. Kalau
   gak ada yang buka beberapa minggu, opportunity baru gak pernah
   kehitung, data GSC gak ke-refresh, agent memory gak ke-update.
2. **Gak ada Internal Linking.** Ini salah satu faktor on-page SEO yang
   paling murah-tapi-berdampak dan sekarang benar-benar gak disentuh sama
   sekali oleh sistem manapun di Sagagoal.
3. **Discovery keyword terbatas ke yang sudah ada di GSC.** Opportunity
   Engine cuma bisa "melihat" query yang situs **sudah** dapat impresi
   untuknya. Buat benar-benar naik ke page 1 buat keyword baru, perlu
   sumber ide topik/keyword di luar histori GSC.

---

## 1b. ATURAN ARSITEKTUR WAJIB — Action Queue sebagai satu-satunya pintu

> **Ini aturan paling penting di dokumen ini.** Ditetapkan 2 Agu 2026 atas
> keputusan eksplisit user, setelah membandingkan alur Sagagoal dengan
> diagram referensi "Val's Cake". Wajib dibaca sebelum mengerjakan agent
> baru mana pun di Fase B/C/D.

**Aturan:** setiap agent — yang sudah ada maupun yang baru — **cuma boleh
menulis hasil kerjanya ke Action Queue (`growth_agent_jobs`)**. Tidak ada
agent yang boleh mengeksekusi aksi sendiri, menulis langsung ke tabel
lain, atau bikin jalur/tabel antrian sendiri di luar `growth_agent_jobs`.

Konsekuensi konkret yang harus dipatuhi saat implementasi:

- **Dilarang bikin tabel antrian baru per-agent.** Internal Linking Agent,
  Keyword Expansion Agent, Technical SEO Auditor, Social Specialist,
  Backlink Monitor — semuanya masuk ke `growth_agent_jobs` yang sudah ada,
  dibedakan lewat kolom `job_type`/`agent_key`, **bukan** lewat tabel
  sendiri-sendiri. (Tabel pendukung buat nyimpen data mentah hasil
  fetch/scan masih boleh — mis. hasil crawl PageSpeed — tapi *usulan yang
  perlu diputuskan manusia* tetap wajib jadi row di `growth_agent_jobs`.)
- **Dilarang ada aksi yang jalan tanpa row di antrian.** Termasuk yang
  kelihatannya sepele: nambah internal link, benerin alt text, bikin
  caption. Semua tetap harus lewat approve.
- **Sinyal ≠ aksi.** "Artikel baru dipublish" itu sinyal yang memicu agent
  *bikin usulan*, bukan izin buat agent langsung ngerjain sesuatu.
- **Measurement loop juga gak boleh nyalip.** Hasil evaluasi before/after
  balik jadi *peluang baru* buat Opportunity Scout, yang nanti lewat
  gerbang & antrian lagi seperti biasa — bukan langsung memicu perubahan.
- **Hasil dicatat balik ke baris yang sama.** Buku besar ini dua sisi:
  sisi usulan (`input_brief` — agent mana, peluang apa, alasannya) dan
  sisi hasil (`output_json` + status + row `growth_agent_feedback` — jadi
  apa, siapa yang putuskan, kapan). Satu baris harus bisa menceritakan
  kejadian penuh dari usulan sampai hasil akhirnya. **Bagian ini tidak
  perlu dibangun** — skema `growth_agent_jobs` sudah menyimpan keduanya
  sejak awal; yang perlu dijaga adalah agent baru ikut pola ini, bukan
  bikin penyimpanan hasil sendiri.

**Kenapa aturan ini ada:** kalau tiap agent boleh jalan sendiri, dua agent
bisa diam-diam ngerjain hal yang tumpang tindih (mis. SEO Specialist
ngusulin rewrite meta sementara Internal Linking Agent ngusulin perubahan
di artikel yang sama) tanpa ada yang lihat bentrokannya. Dengan semua
ngumpul di satu antrian, duplikasi/konflik kelihatan berdampingan di
layar yang sama dan operator bisa memutuskan — bukan ketahuan belakangan
setelah dua-duanya terlanjur jalan.

---

## 2. Karakter/agent baru yang diusulkan

Semua tetap ikutin prinsip yang sudah berjalan: **AI cuma menyarankan,
manusia yang approve, tidak ada aksi otomatis yang mengubah situs live
tanpa review** — plus aturan § 1b di atas (semua wajib lewat Action
Queue).

| # | Nama | Kerjaannya | Kenapa |
|---|---|---|---|
| 1 | **Internal Linking Agent** | Scan artikel published, cari pasangan artikel yang relevan tapi belum saling link, usulkan anchor text + lokasi taruh link. Approve → link ditambahkan ke draft revisi (bukan auto-edit artikel published). | Internal link nyebarin "link equity" antar halaman & bantu Google ngerti struktur topik situs — dampaknya nyata, resikonya rendah (cuma nambah `<a>` tag, gak ubah makna konten). |
| 2 | **Technical SEO Auditor** | Cek Core Web Vitals (lewat PageSpeed Insights API, gratis), ada/gaknya schema markup (Article/BreadcrumbList), alt text gambar yang kosong. Laporan doang, gak auto-fix. | Kecepatan halaman & structured data itu ranking factor teknis yang sekarang sama sekali gak dipantau. |
| 3 | **Keyword Expansion Agent** | Diberi 1 topik/pillar, AI usulkan varian keyword & sub-topik yang relevan buat niche olahraga (bukan cuma dari GSC — dari pengetahuan umum + opsional web search). Hasilnya masuk sebagai draft topic baru di Topic Cluster yang sudah ada, bukan job type baru dari nol. | Opportunity Engine sekarang cuma reaktif ke yang udah keliatan; ini yang isi gap "cari topik yang belum pernah dicoba sama sekali". |
| 4 | **Backlink Monitor** (read-only) | Fetch laporan "Links" dari GSC API (gratis, sudah ada aksesnya) tiap minggu, catat domain yang baru link ke Sagagoal. Murni monitoring, **tidak ada outreach/pencarian backlink otomatis**. | Sinyal off-page tetap relevan buat ranking, tapi outreach backlink otomatis berisiko (bisa keliatan spammy/melanggar guideline Google) — jadi sengaja dibatasi ke monitoring doang. |
| 5 | **Social Specialist** | Setelah artikel dipublish (manual), siapkan draft caption buat 2-3 platform sosmed. **Tidak pernah posting sendiri** — hasilnya nongkrong di Action Queue nunggu di-copy manual sama admin. | Persis pola di diagram Val's Cake — traffic awal dari sosmed bantu artikel baru lebih cepat dapat sinyal engagement. |

**Sengaja tidak diusulkan:** auto backlink outreach, auto-posting sosmed,
auto-merge artikel yang cannibalize satu sama lain — ketiganya beresiko
tinggi kalau salah (reputasi, atau ngerusak SEO yang udah ada) dan butuh
judgment manusia yang gak bisa digantikan AI dengan aman.

---

## 3. Roadmap bertahap (prioritas: dampak SEO vs effort implementasi)

### Fase A — Fondasi (kerjain duluan, dampak ke semua fitur lain)

- ✅ **SELESAI 4 Agu 2026 — Scheduler mandiri.** Ternyata jauh lebih kecil
  dari yang diperkirakan waktu dokumen ini ditulis: folder `cron/` di repo
  SUDAH berisi 5 script CLI yang polanya persis dibutuhkan (sync_fixtures,
  sync_leagues_teams, sync_nba_games, sync_f1_races, sync_f1_standings) dan
  semuanya sudah berjalan di cPanel Cron Jobs. Jadi kerjaannya cuma nambah
  satu file baru `cron/growth_agent_maintenance.php` yang mengikuti pola
  itu — bukan bikin sistem baru. Commit `1396389`, sudah di-deploy dan
  cron-nya terdaftar (`0 4 * * *`, log ke `~/logs/cron/`). Catatan koreksi:
  kalimat "tidak ada cron di codebase ini" yang muncul beberapa kali di
  `docs/ROADMAP.md` dan komentar kode itu keliru kalau dibaca harfiah —
  yang benar adalah *Growth Agent* yang belum punya cron, bukan
  codebase-nya. Komentar di `growth-agent.php` sudah diperbaiki; 5
  pemanggilan lazy di halaman itu sengaja DIPERTAHANKAN sebagai safety net
  yang hidup berdampingan dengan cron.

  Rencana awal (arsip): Ganti trigger "lazy" jadi Cron Job cPanel yang
  hit satu endpoint PHP tiap hari/minggu, manggil fungsi-fungsi
  `*_if_stale()` yang udah ada (`cms_gsc_fetch_if_stale()`,
  `cms_growth_agent_detect_memory_if_stale()`, snapshot performance,
  dst) — **bukan bikin ulang logic-nya**, cuma nambah cara motretnya
  selain page-load admin. Pola cron-nya sama kayak yang baru aja kita
  pasang buat backup (lihat `docs/BACKUP_WORKFLOW.md`), jadi risikonya
  rendah karena sudah terbukti jalan di project ini.
- **Notifikasi ringkasan mingguan.** Bell notification yang sekarang ada
  di admin panel gampang kelewat kalau gak dibuka. Tambahin digest
  simpel (email atau Telegram bot — Telegram lebih murah/instan) yang
  ngirim ringkasan: berapa opportunity baru, berapa job nunggu review.
- ✅ **SELESAI 4 Agu 2026 — SEO-G0 Gate.** Diimplementasikan sebagai
  **peringatan, BUKAN blokir** (keputusan eksplisit user): usulan artikel
  baru tetap dibuat, tapi membawa catatan peringatan yang tampil saat
  operator review. Alasannya menjaga prinsip "sistem menyarankan, manusia
  memutuskan" — gate yang memblokir mencabut kendali operator justru di
  titik paling penting, dan tidak menyediakan jalan keluar kalau gate salah
  menilai. Tiga pengecekan, semuanya deterministik (tanpa AI):
  (A) usulan kembar yang masih menunggu review di `growth_agent_jobs`,
  (B) topik sudah dicakup artikel `published`, (C) sudah tercatat di
  `growth_agent_content_conflicts` open / opportunity `cannibalization_review`.
  Dipasang di dalam `cms_growth_agent_seo_g0_gate()`, dipanggil dari
  `cms_growth_agent_generate_article_idea()` (sebelum panggilan AI — gate
  menilai topik mentahnya, bukan judul karangan AI) dan
  `cms_growth_agent_request_topic_gap_article()` — `seo-intelligence.php`
  tidak perlu diubah sama sekali karena sudah lewat fungsi kedua itu.
  **Nol tabel/kolom baru** sesuai § 1b: hasil gate disimpan di
  `growth_agent_jobs.input_brief` key `seo_g0_gate`, ambangnya di
  `gsc_settings.opportunity_thresholds_json` key `seo_g0_gate`
  (`similarity_threshold` 0.6, `min_overlap_tokens` 2). Metode pencocokan:
  tokenisasi + stopword Indonesia + istilah generik olahraga
  (`jadwal`/`hasil`/`live`/`streaming`/`vs`/dst) dibuang, lalu overlap
  coefficient (bukan Jaccard — query GSC pendek vs judul artikel panjang
  akan menekan skor Jaccard secara tidak adil). UI: badge ⚠ SEO-G0 di panel
  "Job Terbaru", **hanya muncul kalau ada peringatan** — tidak ada menu,
  halaman, atau modul baru. Error di dalam gate tidak pernah menggagalkan
  pembuatan usulan (try/catch berlapis per-pengecekan + pembungkus luar).

  ⚠️ **Keterbatasan yang diketahui:** uji peringatan-palsu cuma dijalankan
  terhadap 2 artikel published di DB lokal (1 pasang) — bukan 24 seperti di
  production. Jadi ambang 0.6 / 2 token **belum tervalidasi di volume data
  asli**. Risikonya terbatas karena gate ini advisory (tidak memblokir):
  kalau ambangnya terlalu longgar hasilnya cuma peringatan berisik, bukan
  fitur rusak. Setelah beberapa usulan artikel dibuat di production,
  evaluasi apakah peringatannya masuk akal, dan tuning angkanya lewat
  `gsc_settings` tanpa perlu ubah kode.

  Rencana awal (arsip): Content Conflict Detection yang sekarang
  ada (kategori opportunity terpisah) diubah jadi pre-check yang jalan
  otomatis SEBELUM `gsc_article_idea`/Topic Cluster bikin usulan artikel
  baru — biar gak ada 2 rekomendasi yang saling tabrakan intent dari
  awal, bukan ketauan belakangan.

### Fase B — Akselerator ranking (dampak langsung ke posisi Google)

- ✅ **SELESAI 4 Agu 2026 — Internal Linking Agent.** Job type baru
  `internal_link_suggestion` (status `manual_action`) di `growth_agent_jobs`
  yang sudah ada — nol tabel baru, sesuai § 1b. Deteksi **deterministik
  tanpa AI**: memakai ulang `cms_growth_agent_g0_tokenize()`/
  `cms_growth_agent_g0_overlap()` milik SEO-G0 Gate (bukan tokenizer kedua),
  mencari artikel published A yang teksnya memuat frasa cocok dengan
  judul/topik artikel B sementara A belum punya link menuju B. Trigger
  manual lewat tombol "Scan Internal Linking" di `growth-agent.php`.
  Halaman review sendiri `internal-link-review.php` (Apply/Reject) karena
  operator wajib melihat anchor + kalimat sekitarnya sebelum memutuskan —
  alasan yang sama dengan `seo-recommendation-review.php`.

  **Penyisipan link aman secara struktural** (bukan `str_replace`/regex ke
  HTML mentah): DOMDocument + XPath
  `text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]`
  — nilai atribut mustahil tersentuh karena XPath hanya menyisir text node.
  Jebakan UTF-8 klasik DOMDocument ditangani lewat prefix
  `<?xml encoding=...>`. Batas kata pakai regex Unicode-aware
  `(?<![\p{L}\p{N}])`. Hanya kemunculan pertama yang valid yang ditautkan
  (menautkan kata sama berkali-kali itu pola spam). Setelah penyisipan,
  HTML hasil di-parse ulang & dihitung jumlah `<a>`-nya
  (`cms_growth_agent_il_verify_safe()`) — kalau tidak sesuai, seluruh
  operasi dibatalkan daripada menyimpan HTML rusak ke artikel live.

  **Apply menulis ke `pages.content`, tapi snapshot isi lama WAJIB disimpan
  dulu** ke `growth_agent_jobs.output_json` (`previous_content` + panjang +
  waktu + anchor + target) — ini syarat mutlak karena **CMS ini tidak punya
  sistem revisi artikel sama sekali**, jadi snapshot itu satu-satunya jalan
  pulang kalau hasilnya keliru. Seluruh operasi (update konten + insert
  feedback + update job) dibungkus satu transaksi dengan `rollBack()`, jadi
  tidak mungkin ada kondisi setengah jadi. Ada guard tambahan: sebelum
  apply, konten **saat ini** dicek ulang — kalau artikel sudah diedit orang
  sejak usulan dibuat, apply ditolak dengan pesan jelas alih-alih menimpa
  perubahan editor. `pages.status` tidak pernah disentuh.

  Ambang & batas jumlah usulan per artikel di
  `gsc_settings.opportunity_thresholds_json` key `internal_linking`,
  tunable tanpa migrasi.

  **Perbaikan kualitas anchor (4 Agu 2026, setelah dipakai di production).**
  Usulan pertama di data asli menghasilkan anchor **"paling"** — satu kata
  keterangan generik yang sempat diterapkan ke artikel live lalu dihapus
  manual operator. Penyebabnya dua: stopword belum mencakup kata
  keterangan/penguat umum bahasa Indonesia, dan frasa satu kata boleh jadi
  anchor tanpa syarat tambahan.

  Perbaikannya **sengaja BUKAN sekadar menambah stopword** — itu jadi
  kejar-kejaran tanpa akhir (besok "sangat", lusa "banget"). Yang dipakai
  dua sinyal independen, keduanya WAJIB lolos untuk anchor satu-kata:
  (1) **document frequency korpus** — token yang muncul di lebih dari
  `single_word_max_df_ratio` (default 20%) artikel published otomatis
  dianggap generik, tanpa peduli kata apa itu, dan ambangnya menyesuaikan
  sendiri seiring korpus bertambah; (2) **kapitalisasi tengah kalimat di
  body artikel sumber** — bukan di judul, karena judul di situs ini banyak
  Title Case sehingga kapitalisasinya bukan sinyal. Fungsi pengeceknya
  eksplisit melewati kemunculan di awal kalimat (setelah `.`/`!`/`?`) dan
  kata pertama teks, jadi yang lolos benar-benar nama diri. Kalau artikel
  published < `min_corpus_size_for_single_word` (default 10), anchor
  satu-kata dimatikan total karena statistik korpus sekecil itu tidak bisa
  dipercaya. Anchor multi-kata tetap wajib ≥2 token bermakna.

  **Bukti solusinya struktural, bukan tambal sulam:** saat diuji di 24
  artikel asli, aturan baru menahan anchor **"Akhir"** (`df = 5/24 = 21%`,
  tepat di atas ambang) — kata yang tidak pernah didaftarkan ke stopword
  mana pun dan tidak pernah disebut di brief. Kalau perbaikannya cuma
  menambah "paling" ke stopword, kasus ini akan lolos.

  Dua bug lain ditemukan devs sendiri saat pengujian dan ikut diperbaiki:
  anchor bisa berakhir dengan tanda baca menggantung ("Piala Dunia 2026,"),
  dan urutan "terpanjang duluan" ternyata tidak selalu benar setelah frasa
  dipangkas (menghasilkan "Indonesia vs Timor" alih-alih "Indonesia vs
  Timor Leste") — diperbaiki dengan sort berdasarkan panjang hasil akhir.

  Hasil akhir di 24 artikel asli: 9 usulan — 7 bagus, 2 cukup, 0 jelek;
  5 dari 9 identik dengan logika lama, jadi tidak ada regresi yang
  mematikan usulan yang benar.

  ⚠️ **Pelajaran proses:** dua masalah kualitas (ambang SEO-G0 tidak
  tervalidasi, dan bug anchor ini) sama-sama lolos karena DB lokal cuma
  punya 2 artikel published sementara production punya 24. Sejak 4 Agu 2026
  DB production disalin ke lokal (dengan seluruh kredensial dikosongkan —
  AI, GSC, dan API-Football) supaya pengujian berikutnya dilakukan di
  volume data yang nyata. Riwayat keterbatasan lama:
  Kualitas anchor & relevansi target baru benar-benar terukur setelah
  dijalankan di data asli. Risiko rendah karena tidak ada yang berubah
  tanpa operator menekan Apply. Catatan proses: scan pertama menghasilkan
  2 dari 6 usulan yang jelek (anchor berupa potongan kalimat tidak wajar);
  diperbaiki dengan memperluas stopword istilah generik bursa-transfer +
  memangkas tepi frasa supaya anchor selalu mulai/berakhir di kata
  bermakna.

  Rencana awal (arsip): **Internal Linking Agent** (lihat § 2).
- **Keyword Expansion Agent** (lihat § 2) — ini yang paling nentuin bisa
  gak nambah artikel yang nembus keyword sama sekali baru, bukan cuma
  optimasi yang udah ranking.
- **Technical SEO Auditor** (lihat § 2).

### Fase C — Distribusi & closing the loop

- **Social Specialist** (lihat § 2).
- **Auto re-trigger measurement loop** — sekarang Feedback Loop
  before/after itu laporan yang dilihat manual; usulan: begitu ada
  perubahan yang di-Apply, otomatis jadwalkan "cek ulang performanya
  dalam 28 hari" biar gak ketinggalan dievaluasi.

### Fase D — Perlu keputusan lebih dulu (jangan langsung kerjain)

- **Backlink Monitor** — teknis gampang (API GSC yang sudah ada), tapi
  perlu diputusin dulu mau ditaruh di halaman mana / seberapa penting
  dibanding fase A-C.
- **Riset keyword pakai API berbayar** (Ahrefs/SEMrush/sejenisnya) —
  Keyword Expansion Agent di Fase B bisa jalan gratis (AI + web search),
  tapi hasilnya gak akan setajam data volume pencarian asli dari tool
  berbayar. Ini trade-off biaya vs akurasi yang perlu didiskusikan dulu,
  bukan diputuskan sepihak di dokumen ini.

---

## 4. Ringkasan: workflow apa yang paling nentuin buat page 1

Diurutkan dari yang paling fundamental:

1. **Konsistensi eksekusi** (Fase A) — percuma logic-nya bagus kalau
   cuma jalan pas admin iseng buka halaman. Ranking itu proses jangka
   panjang, butuh cadence yang konsisten.
2. **Internal linking** — sering diremehkan tapi termasuk faktor on-page
   dengan rasio dampak:effort paling tinggi dari semua yang diusulkan di
   sini.
3. **Cakupan keyword yang lebih luas dari GSC** — tanpa ini, sistem cuma
   bisa mengoptimalkan apa yang udah ada, gak bisa nemuin peluang baru
   yang situs belum pernah coba sama sekali.
4. **Sinyal teknis (speed, schema)** — makin penting kalau volume artikel
   makin banyak, tapi dampaknya lebih kecil dibanding 3 poin di atas
   untuk situs seukuran Sagagoal sekarang.
5. **Sinyal off-page & distribusi (backlink, sosmed)** — dampaknya nyata
   tapi paling lambat keliatan hasilnya dan paling berisiko kalau
   diotomasi sembarangan — makanya sengaja diposisikan paling akhir &
   paling dibatasi dari sisi otomasi.

---

## Aturan pakai dokumen ini

Begitu satu fase/item dari sini mulai dikerjakan beneran (bukan cuma
"disetujui masuk antrian" seperti status sekarang): pindahkan ke
`docs/ROADMAP.md` § Now, dan tandai item itu di sini sebagai "→ SEDANG
DIKERJAKAN, mulai tanggal X" alih-alih dihapus. Begitu selesai: catat di
`docs/ROADMAP.md` § Done seperti biasa, dan tandai di sini "→ SELESAI,
tanggal X". Riwayat diskusi & alasan di dokumen ini gak pernah dihapus,
cuma statusnya yang diupdate — sama kayak prinsip append-only yang
dipakai di `SITEMAP.md`.
