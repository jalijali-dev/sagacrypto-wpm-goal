# SagaCrypto / WPM — Decision Log

> Log keputusan teknis/arsitektur yang sudah diambil di project ini, beserta
> alasannya. Tujuannya supaya keputusan lama tidak "ditemukan ulang" atau
> tidak sengaja dibalik tanpa sadar konsekuensinya. Ditelusuri dari
> `HANDOFF.md` dan `SITEMAP.md` (root) — kalau sebuah keputusan tidak
> menyebutkan alasan eksplisit di sumbernya, itu dicatat apa adanya di sini,
> tidak dikarang-karang.
>
> Entri baru ditambah di **atas** template (paling bawah dokumen), urutan
> terbaru di atas.

---

## 2026-07-15 — Role admin disimpan lowercase, tanpa spasi (`superadmin`/`admin`/`editor`)

**Keputusan:** Kolom `admins.role` adalah `enum('superadmin','admin','editor')`
— lowercase, tanpa spasi. Semua kode yang membandingkan role (`cms_require_role()`,
dsb) wajib membandingkan terhadap nilai ini, bukan label tampilan.

**Alasan:** Dropdown Role di form admin (`cms-admin/pages/admins.php`) selama
ini mengirim `Super Admin`/`Editor`/`Admin` (title case, ada spasi) — tidak
pernah cocok dengan enum DB, jadi setiap admin yang dibuat/diedit lewat form
itu **tidak pernah punya role yang valid**. Ini juga penyebab pembatasan lama
"External Ad Code hanya Super Admin" di `ads.php` diam-diam tidak pernah
berfungsi (`=== 'superadmin'` dibandingkan dengan `'Super Admin'` di session).

**Alternatif yang dipertimbangkan:** Tidak ada alternatif tercatat — ini
murni bugfix yang sekaligus dijadikan aturan wajib ke depan (label tampilan
tetap "Super Admin"/"Admin"/"Editor" di UI, tapi value yang dikirim/disimpan
selalu bentuk lowercase-no-spasi). Validasi server-side tambahan dipasang di
`admins-store.php`/`admins-update.php` sebagai defense-in-depth.

---

## 2026-07-15 — RBAC 3 tier (Editor / Admin / Super Admin) benar-benar membatasi akses

**Keputusan:** Semua admin yang login sebelumnya punya akses identik apa pun
role-nya. Sekarang dibedakan lewat `cms_require_role()`: Editor cuma bisa
Pages & Articles + Media Library + SEO Dashboard + Banners; Admin dapat semua
kecuali Admin Users/AI Credentials/Crypto API (halaman yang menyimpan
kredensial/API key mentah); Super Admin akses penuh.

**Alasan:** Permintaan eksplisit user — sebelumnya tidak ada pembatasan sama
sekali meski UI role sudah ada sejak awal.

**Alternatif yang dipertimbangkan:** Tidak tercatat.

---

## 2026-07-15 — `cms_public_base_prefix()` wajib untuk semua URL absolut admin→frontend

**Keputusan:** Satu-satunya cara yang benar untuk membangun URL absolut dari
admin panel balik ke frontend publik (mis. preview gambar) adalah
`cms_public_base_prefix()` (`cms-admin/includes/functions.php`). `BASE_URL`
mentah **tidak boleh** dipakai untuk ini.

**Alasan:** Topologi deploy adalah split-subdomain (`sagagoal.com` untuk
frontend, `wpm.sagagoal.com` untuk admin — dua host berbeda secara HTTP
meski satu hosting/cPanel & satu MySQL server). `BASE_URL` di dalam admin
selalu merujuk ke domain admin itu sendiri, yang tidak punya file fisik
`/uploads/...` — dipakai untuk build URL ke frontend, hasilnya 404. Ini
penyebab bug berulang (logo/banner preview kosong di production) yang muncul
lagi 15 Jul 2026 (ditemukan inline `<script>` di `site-settings.php` dan
`banners.php` masih pakai `BASE_URL` mentah, bukan `cms_public_base_prefix()`
versi JS-nya) — makanya sekarang eksplisit didokumentasikan sebagai aturan
wajib, bukan cuma konvensi tersirat.

**Alternatif yang dipertimbangkan:** Pakai `BASE_URL` langsung — sudah
dicoba (itu penyebab bug-nya), terbukti salah untuk topologi split-subdomain.

> ⚠️ **Update topologi 7 Agu 2026:** subdomain admin `wpm.sagagoal.com`
> sudah **tidak dipakai lagi** — admin sekarang diakses lewat path di
> domain utama, `https://sagagoal.com/cms-admin/`. Ini bukan lagi
> split-subdomain (dua host HTTP berbeda), tapi nested-path di host yang
> sama — persis topologi "local dev" yang disebut di kode
> `cms_public_base_prefix()`. Keputusan wajib pakai
> `cms_public_base_prefix()` di atas **tetap berlaku dan tetap aman**
> (fungsinya deteksi otomatis dari `HTTP_HOST`/lokasi file, self-healing
> ke topologi baru tanpa perlu ubah kode). Yang **wajib dicek manual** di
> `config/app.php` production: konstanta `BASE_URL` harus
> `https://sagagoal.com/cms-admin` (bukan cuma `https://sagagoal.com`)
> — kalau lupa diupdate, field `admin_url` di endpoint
> `growth-agent-digest.php` (dipakai notifikasi Telegram) jadi kepotong
> gak ada `/cms-admin/`-nya, link di Telegram 404. Ketauan &
> diperbaiki 7 Agu 2026.

---

## 2026-07-13 — Migrasi SQL formal (`cms-admin/migrations/`) sebagai pelengkap, bukan pengganti, auto-migration PHP

**Keputusan:** Selain sistem auto-migration PHP yang sudah lama ada
(`cms_ensure_table()`/`cms_ensure_column()` di `schema-guard.php`, jalan
lazy setiap admin buka halaman terkait), ditambahkan file `.sql` bernomor
urut di `cms-admin/migrations/` sebagai catatan formal.

**Alasan:** Auto-migration PHP tidak bisa dipakai untuk: (1) fresh install
database kosong dalam satu langkah tanpa klik semua halaman admin satu-satu;
(2) dokumentasi/audit trail yang bisa di-grep tanpa baca PHP di banyak file;
(3) disaster recovery/staging setup. Sistem auto-migration PHP tetap jalan
seperti biasa sebagai safety net — tidak dihapus/digantikan.

**Alternatif yang dipertimbangkan:** Tidak tercatat — dua sistem ini memang
dirancang untuk hidup berdampingan, bukan salah satu dipilih.

---

## 2026-07-13 — Migrasi destructive selalu file terpisah, opt-in, tidak pernah auto-run

**Keputusan:** Migrasi yang men-DROP tabel/kolom (008, 012, 013) selalu
dipisah dari migrasi non-destructive, ditulis dengan peringatan jelas di
header file, dan **tidak pernah dieksekusi otomatis** — harus dijalankan
manual oleh user via phpMyAdmin/mysql client.

**Alasan:** Sandbox kerja tidak punya akses DB live sama sekali. Selain itu,
operasi destructive butuh keputusan sadar manusia yang membaca dulu apa yang
akan di-drop — tidak boleh jadi efek samping dari migrasi rutin yang
idempotent dan "aman dijalankan berkali-kali".

**Alternatif yang dipertimbangkan:** Tidak tercatat.

---

## 2026-07-13 — File migrasi lama tidak pernah diedit — selalu file baru

**Keputusan:** Begitu sebuah file migrasi (`00N_*.sql`) sudah dibuat, isinya
tidak diubah lagi meski skema yang dicatatnya berubah/dihapus belakangan
(mis. `007_livescore_api.sql` tetap ada meski modulnya sudah dihapus total
di `013`). Perubahan susulan selalu jadi file baru bernomor berikutnya.

**Alasan:** File migrasi adalah rekaman historis skema pada titik waktu
tertentu — mengedit retroaktif akan merusak catatan riwayat dan berisiko
membuat urutan `IF NOT EXISTS`/`ADD COLUMN IF NOT EXISTS` di lingkungan lain
(yang sudah menjalankan versi lama) jadi tidak konsisten.

**Alternatif yang dipertimbangkan:** Tidak tercatat.

---

## 2026-07-13 — Modul Livescore Sepak Bola dihapus total, bukan disembunyikan

**Keputusan (15 Jul 2026, dirujuk balik ke sini karena berkaitan dengan pola
Fase 6):** Modul Livescore (admin pages, service, frontend page/widget,
route, 3 tabel DB) dihapus sepenuhnya dari project — bukan sekadar
disembunyikan dari sidebar/menu.

**Alasan:** Permintaan eksplisit user — akan dibangun ulang sebagai
project/website terpisah, jadi tidak ada gunanya menyisakan dead code atau
tabel dorman di project ini.

**Alternatif yang dipertimbangkan:** Definisi ENUM `livescore`/`livescore_api`
yang ada di skema lama (`ads.php`/`featured-content.php`/migrasi 004/005)
sengaja **tidak** dibersihkan (`ALTER ENUM` di tabel live dianggap berisiko
tanpa manfaat berarti dibanding manfaatnya) — jadi bukan penghapusan 100%
murni, ada trade-off eksplisit di titik ini.

---

## 2026-07-14 — Fitur "Special Pages" ditarik balik total (bukan dibiarkan separuh jalan)

**Keputusan:** Setelah Special Pages disambungkan ke frontend (13 Jul 2026),
sehari kemudian seluruh fitur (admin panel + frontend route + integrasi
menu) ditarik balik total. Tabel database (`special_pages`, kolom
`show_in_menu`/`menu_order`) **sengaja tidak di-drop** saat itu.

**Alasan:** Tanpa admin panel, toggle "tampilkan di menu" tidak bisa
dikelola sama sekali — mempertahankan separuh fitur (frontend jalan, admin
hilang) dianggap lebih membingungkan daripada menariknya penuh. Tabel DB
dipertahankan sesuai instruksi eksplisit user untuk tidak drop tabel tanpa
kepastian, dan supaya data lama tidak hilang kalau fitur mau dihidupkan lagi.
(Tabel ini baru benar-benar di-drop belakangan, di migrasi `012`, atas
instruksi baru yang lebih eksplisit.)

**Alternatif yang dipertimbangkan:** Mempertahankan frontend saja tanpa
admin — ditolak karena tidak bisa dikelola sama sekali setelah admin
dihapus.

---

## (tidak bertanggal, berlaku sejak awal) — Stack: PHP 8 procedural, bukan framework; tanpa build step

**Keputusan:** Codebase ini PHP 8 procedural (bukan Laravel/Symfony/dll),
PDO langsung ke MySQL, tanpa build step, tanpa dependency Composer/npm
besar — vanilla PHP + vanilla JS/CSS baik di frontend maupun admin.

**Alasan:** Tidak didokumentasikan eksplisit di `HANDOFF.md`/`SITEMAP.md` —
dicatat di sini sebagai keputusan yang sudah berlaku sejak awal project,
bukan hasil evaluasi trade-off yang terekam. Kalau ada konteks tambahan
soal ini (mis. keterbatasan hosting shared/cPanel), tambahkan di sini saat
diketahui.

**Alternatif yang dipertimbangkan:** Tidak tercatat.

---

<!--
## YYYY-MM-DD — <keputusan singkat dalam satu baris>

**Keputusan:** <apa yang diputuskan, spesifik & bisa diverifikasi di kode>

**Alasan:** <kenapa — masalah/constraint/permintaan apa yang mendasarinya>

**Alternatif yang dipertimbangkan:** <opsi lain yang sempat dipikirkan dan
kenapa tidak dipilih — boleh dihapus baris ini kalau memang tidak ada>
-->

## 2026-08-09 — Fase G: auto_draft_article boleh full auto-publish tanpa approval manusia

**Keputusan:** Job `auto_draft_article` (Full Draft Automation — Fase F)
boleh langsung publish artikel ke publik tanpa approval manusia, saat
toggle "Mode Otonom — Auto-Publish Draft" di panel Growth Agent → tab
Otomatisasi dinyalakan manual oleh operator. Ini pengecualian eksplisit
dari aturan default arsitektur project (Action Queue + approval manusia
wajib untuk semua saran Growth Agent). Job_type lain TIDAK terpengaruh,
tetap wajib approval manusia seperti biasa.

Tidak ada rate limit tambahan atau gate warning yang memblokir publish
selama toggle ini ON — SEO-G0 gate dan title-vs-headline check tetap
berjalan dan tetap dicatat di job/output_json untuk audit, tapi hasilnya
TIDAK menahan publish. `max_drafts_per_day` tetap berlaku sebagai
pembatas GENERATE (kontrol biaya AI), bukan pembatas publish.

**Alasan:** Operator (owner situs) secara eksplisit meminta full
otomatisasi (scrape → draft → publish) tanpa campur tangan manusia,
dengan tujuan kecepatan publikasi konten dan skala. Operator sudah
diberi tahu risikonya (artikel bisa salah fakta/mirip-copyright sebelum
sempat direview) dan tetap memilih opsi ini secara sadar, dikonfirmasi
lewat pertanyaan eksplisit sebelum perubahan ini dibuat.

**Alternatif yang dipertimbangkan:** Auto-publish dengan gate (tetap
ditahan manual kalau ada warning SEO-G0/title-mirip-headline) + rate
limit sebagai pengaman minimal — ditawarkan ke operator, ditolak, operator
memilih full-auto tanpa pengaman.

---

## 2026-08-28 — Pass 2.5 (finalization safety net) re-verify skor sebelum force-close ke FT

**Keputusan:** `wpm_sync_fixtures()` (`includes/LivescoreSync.php`) — Pass
2.5, yang men-force-close fixture yang nyangkut di status live
(`1H`/`HT`/`2H`/`ET`/`P`) jadi `FT` 3 jam setelah kickoff, sekarang
re-verify dulu ke API (`/fixtures?date=`, grouped per tanggal, cap 3
tanggal per run — sama pola dengan Pass 2.6 stale-NS) SEBELUM fallback
force-close. Re-verify meng-upsert status DAN skor lewat `$upsertBatch`
yang sudah ada; fallback UPDATE lama (status-only, tidak sentuh skor)
cuma jalan buat fixture yang re-verify-nya gagal/tidak ketemu di API.
Ditambah kolom `fixtures.score_last_verified_at` (DATETIME NULL,
self-heal via `cms_ensure_column()`), di-stamp `UTC_TIMESTAMP()` tiap kali
`$upsertBatch` menyentuh sebuah baris.

**Alasan:** Operator (Raja) lapor skor FT yang salah di production — Real
Madrid vs Real Sociedad ditampilkan FT 2-1, skor final sebenarnya 4-1.
Root cause: Pass 2.5 versi lama murni `UPDATE status_short = 'FT'` tanpa
pernah menyentuh `home_score`/`away_score` — kalau live pass (Pass 2)
kebetulan gagal/skip persis di menit-menit akhir pertandingan (misal
quota API habis — sudah pernah jadi masalah berulang, lihat komentar 24-25
Jul 2026 di file yang sama), skor terakhir yang berhasil tersimpan
terkunci permanen sebagai skor "final" begitu status di-flip ke FT oleh
Pass 2.5, karena tidak ada mekanisme lain di file ini yang pernah
menyentuh ulang fixture yang statusnya sudah FT.

**Alternatif yang dipertimbangkan:** Coba `/fixtures?ids=` untuk
re-verify batch sekaligus (satu call untuk banyak id) — dikonfirmasi TIDAK
tersedia di free plan API-Football account ini (pesan error eksplisit
"Free plans do not have access to the Ids parameter", sama temuan yang
sudah dicatat di komentar Pass 2.6). Grouped-by-date (`?date=`) dipilih
karena sudah terbukti jalan di plan ini (dipakai Pass 1 & Pass 2.6).

---

## 2026-08-30 — Games Hub MVP: vanilla JS/Canvas, desain terpisah dari tema situs, form sign-up ditunda

**Keputusan:** Fitur baru "Sagagoal Games" (`games/index.php` landing hub +
`games/air-hockey/index.php`, aset di `assets/games/`) dibangun dengan 3
keputusan sadar yang perlu diketahui sesi/devs berikutnya biar tidak
diasumsikan "belum sempat dikerjakan":

1. **Stack: Vanilla JavaScript + Canvas 2D API, TIDAK ada game engine**
   (bukan Phaser/PixiJS/Matter.js/dll). Physics air hockey (collision
   lingkaran-vs-lingkaran buat puck-vs-mallet, lingkaran-vs-garis buat
   puck-vs-dinding/gawang) ditulis manual di
   `assets/games/js/air-hockey.js`. AI lawan (Easy/Medium/Hard) juga
   logic manual (reaction-lag + speed cap buat Easy/Medium, linear
   extrapolation posisi puck buat Hard) — bukan machine learning. Loop
   animasi pakai `requestAnimationFrame`, bukan `setInterval`.
2. **Desain visual sengaja BEDA total dari tema situs utama**
   (`assets/games/css/*.css` — sendiri, tidak reuse/extend
   `assets/css/site.css` sama sekali). Situs utama pakai gradient
   oranye-ungu + glass-card; zona Games pakai palet neon gelap
   (hijau/cyan/pink) — operator eksplisit minta "gaming vibe" yang
   kerasa beda dari zona baca berita, dengan packaging tema sepak bola
   (puck = bola, mallet = kaki/sepatu, papan = lapangan) biar tetap
   berasa produk Sagagoal, bukan template generik. Kedua halaman TIDAK
   include `includes/site-header.php`/`site-footer.php` (nav/tema situs
   utama tidak relevan di sini) — hanya `includes/site-bootstrap.php`
   buat akses `$pdo`/helper, markup & `<head>` sendiri sepenuhnya. Tetap
   ada link "← Kembali ke Sagagoal"/"← Games" di kedua halaman biar user
   tidak merasa nyasar keluar situs.
3. **Form sign-up/data member dan leaderboard server-side SENGAJA
   DITUNDA** ke fase berikutnya, bukan belum sempat. Skor MVP ini
   murni in-memory JS (`assets/games/js/air-hockey.js`), reset begitu
   halaman di-refresh — tidak ada tabel DB, tidak ada API buat nyimpen
   skor. Rencana ke depan: form baru muncul belakangan (soft-ask pas
   user mau submit skor/leaderboard, bukan gate di depan sebelum main),
   field/insentif/consent belum diputuskan — perlu didiskusikan ulang
   sebelum fase itu dikerjakan.

**Alasan:** Payload budget — operator eksplisit minta halaman game di
bawah ~200KB total (lebih ringan dari 1 foto artikel biasa di situs
ini) supaya tidak bikin berat situs utama; game engine manapun nambah
ratusan KB buat kebutuhan air hockey yang sebenarnya sederhana secara
fisika. Desain terpisah karena tujuan fitur ini murni retensi/engagement
(orang betah, ada alasan balik lagi), BUKAN SEO — game tidak relevan
secara keyword dengan "berita bola", jadi kedua halaman diberi
`<meta name="robots" content="noindex, follow">` (bukan permintaan
eksplisit operator, tapi konsisten dengan framing "bukan buat
SEO/ranking" di brief — gampang dibalik kalau ternyata operator mau
game ini ke-index juga).

**Bug ditemukan+diperbaiki selama development (bukan bagian scope awal,
dicatat di sini biar sesi berikutnya tahu ini sudah ditangani):**
`includes/site-bootstrap.php`'s `wpm_base_href()`/`wpm_site_url()`
menurunkan site root dari `dirname($_SERVER['SCRIPT_NAME'])` — ini benar
untuk file flat di root (semua halaman publik yang sudah ada sebelumnya:
`index.php`, `artikel.php`, dst.), tapi SALAH untuk script yang
sebenarnya duduk di dalam subdirektori sungguhan seperti `games/index.php`
(dirname-nya jadi `.../games`, bukan root situs). `games/index.php` dan
`games/air-hockey/index.php` adalah entry point publik PERTAMA yang
sungguh-sungguh nested di subdirektori, jadi bug ini baru pertama kali
kena. Fix-nya LOKAL ke 2 file games tsb saja (bukan ubah
`wpm_base_href()`/`wpm_site_url()` yang dipakai luas di halaman lain) —
`<base href>` di kedua file di-hardcode relatif (`"../"` untuk
`games/index.php`, `"../../"` untuk `games/air-hockey/index.php`) dan
semua link internal di dalamnya jadi root-relative biasa, bukan
`wpm_site_url()`. Kalau ke depan ada halaman publik baru lain yang juga
nested di subdirektori, waspadai bug yang sama.

**Alternatif yang dipertimbangkan:** Reuse `assets/css/site.css`/nav
situs utama buat Games (ditolak — bertentangan langsung dengan permintaan
eksplisit operator soal "gaming vibe" yang beda). Perbaiki
`wpm_base_href()`/`wpm_site_url()` supaya generik untuk kedalaman
subdirektori berapa pun (lebih "benar" secara arsitektur, tapi berisiko
lebih tinggi — dipakai di puluhan tempat lain yang semuanya sudah
terbukti benar untuk kasus flat-file; scope brief ini cuma minta MVP
games, jadi fix lokal yang lebih sempit dan lebih aman dipilih dulu).

---

## 2026-08-30 — Games Hub revamp: audio disintesis (bukan file CC0), efek visual Canvas-native

**Keputusan:** Lanjutan langsung dari entri "Games Hub MVP" di atas
(bukan scope baru) — physics/collision/AI di `assets/games/js/air-hockey.js`
TIDAK disentuh sama sekali, semua penambahan di bawah ini hook ke event
yang sudah ada (`goalResult` dari `updatePuck()`, guard collision di
`reflectOffMallet()`, dan `endMatch(playerWon)`).

1. **Audio: semua sound effect disintesis runtime pakai Web Audio API
   (`OscillatorNode` + `GainNode`, envelope attack-cepat/decay-eksponensial),
   BUKAN file audio CC0 yang di-download** dari freesound.org/Kenney.nl
   seperti disarankan di brief. Wall-bounce (blip square wave pendek),
   mallet-hit (sweep pitch turun, "thwack"), goal (arpeggio 3 nada naik),
   win (arpeggio 4 nada + nada tinggi penutup), lose (3 nada turun,
   sawtooth). Musik latar (ditandai OPSIONAL di brief) SENGAJA TIDAK
   dikerjakan di pass ini — semua cue WAJIB (wall/mallet/goal/game-end)
   sudah ada.
2. **Sumber lisensi: N/A — tidak ada aset eksternal dipakai sama
   sekali.** Ini keputusan sadar, bukan lupa cari aset: sintesis prosedural
   nambah 0 byte ke payload (vs budget 300-400KB yang dikasih longgar
   justru buat nampung audio) dan nol permukaan lisensi/atribusi untuk
   diaudit — lebih aman daripada verifikasi manual lisensi CC0 tiap file
   yang di-download. `AudioContext` dibuat lazy di `initAudio()`, dipanggil
   dari handler klik tombol "Mulai Main" (gesture user asli) — bukan saat
   page load, sesuai requirement brief soal autoplay-block browser.
   Tombol mute/unmute (`#ah-mute-btn`, emoji 🔊/🔇) selalu terlihat di
   topbar, toggle `audioEnabled` yang dicek di setiap `playTone()` call.
3. **Efek visual: semua Canvas API native, tidak ada gambar/particle
   library.** Glow puck+mallet pakai `ctx.shadowBlur`/`ctx.shadowColor`
   (diperbesar & dihangatkan warnanya dari versi MVP, radius lebih besar
   + halo tambahan warna oranye/kuning meniru referensi operator). Tekstur
   rink pakai `ctx.createRadialGradient()` (vignette, dibuat sekali lalu
   di-cache di `rinkGradient`, bukan dibangun ulang tiap frame) + garis
   mow-stripe transparan tipis — bukan image asset, supaya 0 byte
   tambahan dan tetap tajam di resolusi berapa pun (DPR-independent).
   Particle burst pas gol (`spawnBurst()`/`updateParticles()`/
   `drawParticles()`) adalah array kecil `{x,y,vx,vy,life}` custom, meluruh
   habis dalam ~50 frame (~0.8 detik di 60fps) — "sekilas" sesuai brief,
   bukan animasi panjang. Ditambah flash-alpha sekilas (`flashAlpha`,
   rgba overlay full-canvas) dan trail memudar di belakang puck
   (`state.trail`, array posisi terakhir yang di-cap 8 titik).
   Scoreboard "gamer" (bevel/gradient di angka skor, pill gradient buat
   badge level) murni CSS (`assets/games/css/air-hockey.css`) —
   `background-clip: text` buat gradient di angka, `box-shadow` berlapis
   (inset highlight + inset shadow) buat efek bevel plat metal.
4. **Landing `/games/`: warna aksen per-card** (`$wpmGames[]['accent']`
   di `games/index.php` — orange/cyan/purple, dibaca lewat CSS custom
   properties `--card-accent`/`--card-accent-rgb` per class
   `.wpm-game-card--{accent}` di `assets/games/css/games-landing.css`)
   plus background gradient yang perlahan bergeser (`@keyframes
   wpmGamesBgDrift`, posisi-only jadi murah secara compositor, dihormati
   `prefers-reduced-motion`). Ikon tetap dari `wpm_icon()` yang sudah ada
   (football/trophy/flame) — cuma warnanya yang beda per card, identitas
   Sagagoal/bola tetap dipertahankan persis sesuai constraint brief,
   bukan ditiru mentah dari referensi CrazyGames (hockey generik/game
   lain).

**Alasan:** Payload budget (300-400KB) dan constraint "vanilla JS,
jangan nambah library berat" dari brief MVP tetap berlaku penuh di
revamp ini — sintesis audio prosedural adalah cara TERMURAH secara byte
buat penuhi requirement "audio wajib ada", bukan sekadar preferensi;
total payload halaman air-hockey naik dari ~30KB (MVP) jadi ~45KB
(revamp ini, HTML+CSS+JS gabungan, audio 0KB) — jauh di bawah budget.

**Alternatif yang dipertimbangkan:** Sourcing SFX pendek dari
freesound.org/Kenney.nl sesuai saran eksplisit brief — tidak dijalankan
karena sesi ini tidak punya cara aman buat verifikasi lisensi tiap file
yang di-download (freesound.org campur CC0/CC-BY/CC-BY-NC per uploader,
salah pilih = bug lisensi yang gampang lolos review), sementara sintesis
prosedural menghilangkan masalah itu sepenuhnya sambil tetap sesuai
alasan asli requirement (SFX ringan, singkat, gaming vibe). Kalau
operator lebih suka SFX rekaman asli (misal suara peluit/sorak beneran),
itu penggantian yang gampang di fase berikutnya — cukup ganti isi fungsi
di object `sfx` (`assets/games/js/air-hockey.js`) dari `playTone(...)`
jadi `new Audio('assets/games/audio/....mp3').play()`, tidak perlu ubah
titik hook-nya sama sekali.

---

## 2026-09-02 — Games Hub game #2: Penalty Kick

**Keputusan:** Game kedua di Games Hub — `games/penalty-kick/index.php` +
`assets/games/css/penalty-kick.css` + `assets/games/js/penalty-kick.js`,
diaktifkan di landing lewat 2 field di `$wpmGames` (`games/index.php`):
`href` -> `'games/penalty-kick/'`, `status` -> `'Main Sekarang'` (`accent`
tetap `cyan`, sudah didefinisikan sejak revamp visual pertama).
`games/air-hockey/*` dan `assets/games/{css,js}/air-hockey.*` TIDAK
disentuh sama sekali.

1. **Gameplay: 5 tendangan penalti, klik/tap salah satu dari 5 zona
   gawang** (top-left/top-right/center/bottom-left/bottom-right) — bukan
   drag-aim bebas. AI kiper "commit" ke satu zona tebakan PAS SAAT
   ditendang (`pickKeeperZone()`), bukan mengejar bola yang sedang
   terbang — sama seperti kiper asli membaca ancang-ancang, bukan
   membaca lintasan bola. Kalau zona tebakan kiper == zona tendangan
   pemain -> SAVED, kalau beda -> GOAL. Tidak ada physics/collision
   sungguhan (cuma perbandingan string id zona) — sesuai instruksi brief
   "hindari physics rumit".
2. **Skor: fixed 5 tendangan (bukan "sampai gagal"), gol dihitung dari
   5.** Dipilih karena paling simpel diimplementasikan (tidak perlu
   simulasikan AI ganti peran jadi penendang) dan progress-nya jelas buat
   pemain (`TENDANGAN X/5` di scoreboard). End-screen ada 4 tingkatan
   pesan (5/5 "Sempurna!", 3-4 "Bagus banget!", 1-2 "Lumayan", 0
   "Yah, coba lagi!").
3. **Difficulty (Easy/Medium/Hard) = probabilitas kiper nebak zona yang
   benar**, bukan mekanisme baru: `correctChance` 0.15/0.40/0.65 —
   diverifikasi lewat simulasi 20.000 trial per level (observed save
   rate 14.7%/39.5%/64.8%, cocok dengan target). `diveFrames` (durasi
   animasi dive kiper) beda per level juga (24/18/13 frame) — kosmetik
   doang (reaksi "lambat" secara visual di Easy), keputusan kiper sudah
   dikunci duluan di awal animasi jadi ini tidak mempengaruhi hasil,
   cuma feel.
4. **Visual/audio konsisten dengan Air Hockey revamp, tapi kode
   terpisah (di-duplicate, bukan di-share/import)** — sesuai instruksi
   brief eksplisit "jangan modify air-hockey.css/js" dan opsi
   ekstraksi-ke-shared-file ditandai optional/jangan-dipaksakan. Pola
   yang diduplicate: `playTone()` (Web Audio API synth, sama persis
   dengan air-hockey.js), particle burst + flash sekilas pas
   gol/saved, `ctx.shadowBlur` buat glow bola & kiper, gradient
   radial buat tekstur lapangan (di-cache di `pitchGradient`, sama
   pola `rinkGradient` di air-hockey.js), scoreboard bevel/gradient CSS
   (di `penalty-kick.css`, class prefix `wpm-pk-*` bukan `wpm-ah-*`
   biar jelas file mana yang mendefinisikan apa — halaman ini juga
   tidak pernah load `air-hockey.css` sekalian jadi sebenarnya tidak ada
   resiko collision, ini cuma soal kerapian). Tombol `.wpm-ah-btn`/
   `--primary`/`--ghost` genuinely DI-REUSE langsung dari
   `games-landing.css` (bukan diduplicate) karena itu memang sudah
   didefinisikan sebagai shared button style sejak awal.
5. **Base href**: `<base href="../../">` di-copy verbatim dari
   `games/air-hockey/index.php` (2 level di bawah root, sama persis) —
   lihat entri 30 Agu 2026 "Games Hub MVP" buat kenapa
   `wpm_base_href()`/`wpm_site_url()` tidak dipakai di halaman games
   yang nested di subfolder.

**Alasan:** Konsistensi produk (operator eksplisit minta game baru
"HARUS konsisten visual/audio-nya dengan Air Hockey") ditimbang lebih
tinggi daripada DRY/reuse-code — brief sendiri bilang ekstraksi shared
helper "optional, jangan dipaksakan kalau bikin over-engineer untuk 1
game kedua", jadi duplikasi kecil (~40 baris `playTone`+particle system)
dipilih daripada bikin file shared baru buat baru 2 game.

**Verifikasi:** Sesi devs ini tidak bisa observe animasi real-time
`requestAnimationFrame` secara visual di environment testing-nya
(`document.hidden` true di browser pane saat development — dikonfirmasi
ini environment quirk, BUKAN bug kode, lewat tes independen: bahkan
Air Hockey yang sudah production-ready pun rAF-nya juga tidak jalan di
kondisi yang sama). Sebagai gantinya, logic inti (state machine
kick-animate-resolve-reset, dan distribusi probabilitas
`pickKeeperZone()`) diverifikasi lewat simulasi terisolasi (replika
persis logic-nya, di-tick manual tanpa rAF) — hasilnya benar (5/5
tendangan ke-resolve dengan urutan goal/saved yang sesuai skenario test,
skor akhir cocok). Operator/reviewer disarankan test manual
klik-per-klik di browser sungguhan sebelum full-confidence deploy,
sebagai lapis verifikasi tambahan yang sesi ini tidak bisa lakukan
sendiri.

**Alternatif yang dipertimbangkan:** Drag-to-aim bebas (bukan 5 zona
fixed) — brief eksplisit izinkan devs pilih, 5-zona dipilih karena lebih
simpel diimplementasikan dengan vanilla JS (tidak perlu drag-tracking
state, langsung cocok dipetakan ke click/touchend tunggal) dan lebih
konsisten UX-nya sama pola "3 tombol difficulty" yang sudah ada. Skema
"pemain terus nendang sampai gagal" (bukan fixed 5) — ditolak karena
kurang predictable durasinya buat 1 sesi main, dan "5 tendangan tetap"
lebih gampang di-tampilkan progress-nya (`X/5`) ke pemain.

---

## 2026-09-02 — Penalty Kick, revisi visual: figur manusia, gawang/bola lebih realistis, pilih timnas

**Keputusan:** Lanjutan langsung dari entri "Games Hub game #2" di atas
(bukan scope baru) — murni render + 1 fitur kosmetik baru, LOGIC
gameplay/scoring/AI (`pickKeeperZone()`, `DIFFICULTY_TUNING`,
perbandingan zona gol/save di `loop()`) sama sekali tidak disentuh,
diverifikasi ulang lewat simulasi state-machine terisolasi setelah
setiap perubahan (hasil identik dengan sebelum revisi: urutan
goal/saved yang sama persis untuk skenario test yang sama).

1. **Figur manusia — keeper (gold) & penendang (cyan).** Operator lapor
   tampilan production cuma lingkaran-lingkaran ("ga kliatan ky orang
   lagi nendang... kya bukan pinalti kick"). `drawKeeper()` di-rewrite
   jadi stick-figure (kepala/torso/lengan/kaki) digambar dalam koordinat
   lokal via `ctx.translate()+ctx.rotate()` sehingga seluruh figur
   miring ke arah zona yang dituju, bukan cuma posisinya yang pindah.
   `drawKicker()` (fungsi baru, sebelumnya TIDAK ADA figur penendang
   sama sekali) — pose berubah sesuai `state.phase`: 'aiming' (berdiri
   santai), 'kicking' (ancang-ancang → ayun kaki, `state.kickFrame`
   dibaca DI SINI SAJA, tidak pernah memengaruhi shotZone/keeperZone),
   'animating'/'resolved' (follow-through beku). Fase `'kicking'` baru
   disisipkan di antara `'aiming'` dan `'animating'` di `loop()` — cuma
   penundaan render sebelum animasi bola yang SUDAH ADA mulai jalan;
   `sfx.kick()` dipindah supaya bunyi pas kaki "kena" bola (akhir
   wind-up), bukan pas klik.
2. **Gawang & bola lebih realistis**, mengikuti referensi screenshot
   operator (foto gawang 3D + bola bertekstur pentagon). `drawGoal()`:
   ditambah "back frame" yang di-offset naik+ke-dalam
   (`GOAL_DEPTH_X`/`GOAL_DEPTH_Y`) + garis strut penghubung — trik
   render murni (bukan transform 3D beneran) biar kerasa kotak, bukan
   bingkai foto rata. `drawBall()`: pola pentagon+seam (`drawBallPattern()`,
   1 pentagon tengah + garis jahitan radiating, bukan tessellation penuh
   — cukup buat kebaca sebagai bola sepak dari jarak render game ini),
   radial gradient buat volume bola (bukan warna putih flat), plus
   `scale` opsional yang mengecil seiring bola mendekati gawang
   (dihitung murni dari `state.ball.y` yang sudah ada, efek depth
   kosmetik doang, tidak pernah ditulis balik ke state/physics).
3. **Layar pilih timnas (fitur baru, request lisan operator di tengah
   sesi, bukan bagian brief tertulis awal)** — panel `#pk-panel-team`
   ditambah SEBELUM panel difficulty-picker, grid ~42 negara peserta
   Piala Dunia asli (bukan daftar karangan) dengan flag emoji Unicode
   (`TEAMS` array di `penalty-kick.js`) — zero image asset, zero
   payload tambahan, pola yang sama dengan alasan audio disintesis di
   entri revamp sebelumnya. Pilihan timnas MURNI KOSMETIK: cuma ganti
   flag yang muncul di scoreboard (`#pk-team-flag-badge`) dan teks hint
   panel start — tidak pernah dibaca oleh `pickKeeperZone()`/logic
   manapun. Tombol "Ganti negara" balik ke panel pilih timnas kapan
   saja; `selectedTeam` tetap tersimpan lewat restart ("Ganti Level/
   Ulang") dalam 1 sesi halaman (di-reset ke belum-pilih hanya kalau
   reload halaman, konsisten dengan skor yang juga in-memory-only).

**Alasan:** Feedback operator eksplisit soal "ga kliatan ky penalti" —
prioritas utama tetap keterbacaan tema (figur manusia), scope
ball/goal-polish dan team-select ditambahkan di sesi yang sama atas
permintaan langsung operator (bukan devs berinisiatif sendiri
memperluas scope) setelah dikonfirmasi ulang lewat pertanyaan eksplisit
sebelum dikerjakan.

**Verifikasi:** Sama seperti entri sebelumnya, `requestAnimationFrame`
tidak jalan di environment testing sesi devs ini (`document.hidden`
true di browser pane) — kali ini berhasil disiasati dengan monkey-patch
sementara `window.requestAnimationFrame`/`cancelAnimationFrame` ke
`setTimeout`/`clearTimeout` HANYA untuk keperluan testing manual (tidak
di-commit, tidak ada di kode production), yang berhasil memunculkan
animasi asli di browser dan mengonfirmasi visual: figur keeper/penendang
tampil & bergerak benar, wind-up→tendang→bola melesat→resolve semua
kejadian dengan urutan yang benar (goal & save dua-duanya diuji, keduanya
benar), gawang dengan depth terlihat, grid pilih timnas scroll & pilih
dengan benar di desktop maupun mobile (375px), restart/ganti-negara
mempertahankan/reset state dengan benar sesuai desain.

**Alternatif yang dipertimbangkan:** Full pentagon/hexagon tessellation
buat bola (seperti bola sepak asli) — ditolak, terlalu banyak draw call
buat ukuran render sekecil ini (~10-13px radius), pentagon tengah +
garis jahitan sudah cukup kebaca sebagai "bola sepak" tanpa ongkos
render berlebih. Transform 3D beneran (`ctx.transform()` matrix) buat
gawang — ditolak, trik "back frame + strut" jauh lebih simpel ditulis
dan dipahami ulang nanti, hasil visualnya cukup buat tujuan (bukan
render 3D presisi).

---

## 2026-09-02 — Penalty Kick: hapus target-zone rings, pseudo-3D lebih kuat, canvas lebih lebar

**Keputusan:** Lanjutan langsung dari 2 entri "Penalty Kick" di atas
(bukan scope baru). Sekali lagi, LOGIC gameplay/scoring/AI sama sekali
tidak disentuh — `ZONES`, `nearestZone()`, `attemptKick()`,
`pickKeeperZone()`, `DIFFICULTY_TUNING` semua identik dengan sebelumnya.

1. **Three.js/WebGL vs Canvas 2D — keputusan operator, final.** Operator
   dikasih 2 opsi eksplisit (3D beneran pakai Three.js vs pseudo-3D tetap
   Canvas 2D) dan MEMILIH pseudo-3D — prioritas tetap payload ringan
   dibanding kemiripan 1:1 ke referensi screenshot (game 3D asli dengan
   tekstur/lighting real-time yang memang tidak mungkin ditiru Canvas
   2D). Dicatat eksplisit di sini biar sesi/devs berikutnya TIDAK
   mengusulkan ulang migrasi ke WebGL — ini bukan devs belum sempat
   coba, ini keputusan operator yang sudah final.
2. **`drawZoneHints()` (4 lingkaran outline target-zone) DIHAPUS
   total** dari `penalty-kick.js`, bukan cuma disembunyikan — operator:
   "tanda digawang nya itu gausah". Sengaja TIDAK diganti indikator lain
   (hover-only, dst.) — brief eksplisit minta default ke "polos tanpa
   hint" kalau ragu. Zona klik/tap itu sendiri (`ZONES` array,
   `nearestZone()`) 100% tidak berubah — cuma indikator visualnya yang
   hilang, area yang bisa diklik pemain persis sama seperti sebelumnya
   (diverifikasi lewat klik nyata di 5 titik berbeda, semua ke-resolve
   benar).
3. **Pseudo-3D diperkuat** (semua Canvas API native, 0 library baru):
   `GOAL_DEPTH_X`/`GOAL_DEPTH_Y` naik dari 10/16 ke 18/26 (vanishing
   point lebih jelas), gradient linear menggelapkan "atap" net gawang
   ke arah belakang (`roofShade` di `drawGoal()`), garis lapangan baru
   yang menyempit ke arah gawang (`drawPitchPerspectiveLines()`,
   fungsi baru — pengganti mow-stripe band yang cuma garis lurus
   sejajar), bayangan elips di bawah kicker & keeper (dulu cuma ada di
   bola, sekarang konsisten di ketiga elemen — ukuran/opacity beda per
   elemen, kicker paling besar karena paling dekat kamera), gradient
   radial di kepala kicker & keeper (dulu flat fill, sekarang ada
   kesan volume sama seperti bola). Depth-scaling bola (mengecil makin
   jauh) TIDAK diterapkan ke kicker/keeper — keduanya secara posisi
   selalu di bidang kedalaman yang sama (kicker di penalty spot, keeper
   di garis gawang, gerakannya lateral bukan menjauh/mendekat kamera),
   jadi scaling-nya tidak masuk akal secara visual untuk mereka; depth
   cue buat keduanya cukup lewat bayangan+shading di atas.
   Perubahan sudut kamera/viewpoint — TIDAK dikerjakan, brief sendiri
   bilang ini opsional dan "bukan keharusan kalau terlalu banyak
   refactor" — mengubah sudut pandang berarti re-derive ulang semua
   koordinat ZONES/goal/figure, resiko lebih tinggi dari manfaatnya
   untuk pass revisi ini.
4. **Canvas/area main diperlebar** — `.wpm-pk-stage` naik dari 520px ke
   1100px max-width (cuma jadi ceiling terluar; `.wpm-pk-panel` non-
   overlay dikunci balik ke 480px + margin auto biar panel teks/pilih-
   timnas/difficulty tidak ikut jadi lebar aneh, cuma `.wpm-pk-board`/
   `.wpm-pk-canvas-wrap` yang benar-benar memakai lebar penuh).
   `.wpm-pk-canvas-wrap` naik dari 420px -> 560px (mobile/tablet) dan
   460px -> 900px (desktop, `min-width:768px`). `aspect-ratio:4/3` yang
   sudah ada otomatis menjaga proporsi tinggi (900px lebar -> 675px
   tinggi), tidak perlu rule tinggi terpisah. TIDAK ada perubahan di
   `games/penalty-kick/index.php` — tidak ada width yang di-hardcode di
   markup, semua sizing murni CSS. TIDAK implementasi Fullscreen API
   (`requestFullscreen()`) — sesuai larangan umum project ini soal
   kontrol macet lintas platform, "lebih lebar" dicapai lewat CSS
   sizing biasa, bukan mode fullscreen browser.

**Alasan:** 3 permintaan operator terpisah tapi murni visual/CSS,
dikerjakan dalam 1 pass karena semuanya di file yang sama dan saling
tidak berkaitan secara teknis (independent, tidak ada ketergantungan
urutan antar poin 1-4).

**Verifikasi:** Lingkaran target-zone dikonfirmasi hilang total dari
render (visual check + grep kode, tidak ada pemanggilan
`drawZoneHints()` tersisa). Klik/tap 5 zona diuji ulang setelah
perubahan lebar canvas — `pointerToLogical()` (berbasis
`getBoundingClientRect()`) otomatis menyesuaikan ke ukuran CSS baru
tanpa perlu perubahan kode, dikonfirmasi lewat klik nyata di ukuran
desktop lebar (900px) dan mobile (375px), keduanya ke-resolve gol/save
dengan benar. `requestAnimationFrame` tetap tidak jalan native di
environment testing sesi devs ini (sama seperti 2 entri sebelumnya) —
disiasati lagi dengan monkey-patch sementara ke `setTimeout` (testing
only, tidak ada di kode production) buat verifikasi visual manual.

**Alternatif yang dipertimbangkan:** Ganti sudut kamera jadi lebih
"dari belakang-bawah bola" sesuai saran brief — ditimbang, ditunda
(bukan ditolak permanen) karena butuh re-derive semua koordinat
ZONES/GOAL_*/figure yang saat ini sudah battle-tested; kalau operator
masih merasa kurang "3D" setelah pass ini, itu next candidate yang
jelas paling mahal secara refactor dari semua opsi yang ada di brief.

---

## 2026-09-03 — Games Hub: game ketiga, Kuis Bola (timed multiple choice)

**Keputusan:** Game ketiga dari 3 slot yang sudah direncanakan sejak
Games Hub MVP (30 Agu 2026) ditambahkan — slug `quiz-bola`, sudah punya
card + logo PNG di landing sejak revisi 3 Sep 2026 sebelumnya (status
"Segera Hadir"), sekarang jadi "Main Sekarang". Operator secara
eksplisit menyebut instalasi awal ketiga game (Air Hockey, Penalty
Kick, Kuis Bola) sebagai **"versi 1"** — polish grafik lanjutan untuk
ketiganya jadi "versi 2" di brief terpisah nanti, dicatat di sini biar
sesi berikutnya tidak salah kira scope brief ini termasuk over-polish.

File baru: `games/quiz-bola/index.php`, `assets/games/css/quiz-bola.css`,
`assets/games/js/quiz-bola.js`. File modified: `games/index.php` (cuma
field `href`/`status` untuk entry `quiz-bola` di `$wpmGames`, persis
pola `air-hockey`/`penalty-kick`).

1. **Sumber soal: hardcode di JS, bukan database+admin panel** —
   keputusan operator, dipilih eksplisit dari 2 opsi yang ditanyakan.
   `QUESTION_BANK` di `quiz-bola.js` berisi **70 soal** (di atas target
   minimal 40-60 di brief) — campuran aturan dasar, sejarah Piala
   Dunia, klub-klub besar Eropa, pemain terkenal, plus sebagian kecil
   soal Indonesia (Liga 1, Timnas, pemain naturalisasi) biar relevan
   buat audiens Sagagoal tanpa jadi mayoritas niche. Tidak ada
   backend/API/tabel DB baru — 100% klien, tidak setengah-setengah.
   Tiap sesi: `pickSessionQuestions()` random-pick 10 dari bank
   (`shuffle()` Fisher-Yates) + shuffle independen urutan opsi jawaban
   tiap soal (jadi jawaban benar tidak selalu di slot yang sama), hasil
   shuffle-nya objek baru — `QUESTION_BANK` sendiri tidak pernah
   dimutasi.
2. **Model gameplay: Timed multiple choice ala Kahoot** — keputusan
   operator, bukan survival/streak atau kuis tanpa timer. 1 sesi = 10
   soal random. 3 level kesulitan dibedakan lewat **durasi timer per
   soal**, bukan bank soal terpisah per level (sesuai saran brief,
   supaya tidak 3x kerjaan bikin soal): Easy 18 detik, Medium 12 detik,
   Hard 8 detik (`DIFFICULTY_TIMER_MS`). Skor: `BASE_POINTS` (1000)
   dikali fraksi waktu tersisa saat menjawab benar (linear falloff,
   `Math.round(1000 * (1 - elapsed/duration))`), jawaban salah atau
   waktu habis = 0 poin untuk soal itu — insentif jawab cepat terasa
   langsung di angka (diuji: klik instan dapat ~950-1000 poin, klik
   mendekati waktu habis dapat puluhan poin, timeout dapat 0). Setelah
   10 soal: overlay skor total + "X dari 10 jawaban benar" + tombol
   "Main Lagi" (reset penuh ke panel start, termasuk re-pick 10 soal
   baru di sesi berikutnya).
3. **Timer visual "genting" ala Kahoot** — bar (`.wpm-qb-timer-fill`)
   di-drive murni lewat CSS `transition: width linear <durasi>s`
   (instant reset ke 100% tanpa transisi, lalu di-set ke 0% di frame
   berikutnya biar transisi-nya benar-benar animasi), bukan redraw tiap
   frame — ringan, tidak butuh rAF buat elemen visualnya sendiri. Warna
   berubah ke merah/oranye (`.is-urgent`) saat sisa waktu ≤25% durasi.
   Skor TETAP dihitung presisi dari `performance.now()` di satu-
   satunya rAF loop di file ini (`startTimer()`'s `poll()`) — loop ini
   cuma baca jam & cek waktu habis, tidak pernah menggambar apa pun,
   beda total dari render loop Canvas di 2 game sebelumnya.
4. **DOM/CSS, bukan Canvas** — brief eksplisit bilang kuis pilihan
   ganda lebih natural sebagai DOM (kartu soal, tombol pilihan, timer
   bar) dibanding dipaksa ke Canvas seperti Air Hockey/Penalty Kick.
   Tidak ada `<canvas>` sama sekali di `games/quiz-bola/index.php`.
5. **Audio & visual konsisten** — pola sintesis nada Web Audio
   (`initAudio()`/`playTone()`/objek `sfx`) di-copy ulang dari
   air-hockey.js/penalty-kick.js (by design, bukan import — tetap self-
   contained per file, sama seperti 2 game sebelumnya), dengan cue baru
   yang relevan buat kuis: `correct` (2-note naik), `wrong` (sawtooth
   turun), `timeout` (nada rendah), `tick` (blip pendek 3 detik
   terakhir), `finish` (4-note arpeggio di layar akhir). Mute button di
   topbar, pola sama persis. Accent warna **purple** (`#d374ff` /
   `211,116,255`) didefinisikan lokal di `quiz-bola.css` (bukan baca
   custom property `.wpm-game-card` yang cuma ada di scope card
   landing) — sudah senada dengan `accent: 'purple'` yang dipasang di
   `games/index.php` sejak card ini dibuat. `games-landing.css` dan
   file game lain tidak disentuh sama sekali.
6. **Mobile/touch** — grid opsi jawaban 2 kolom di layar ≥421px,
   turun ke 1 kolom di layar sempit (`@media max-width:420px`), tombol
   `min-height:52px` biar cukup besar buat di-tap.

**Alasan:** Semua keputusan gameplay (sumber soal, model timed-MC,
skema skor, pemisahan level lewat durasi timer bukan bank soal) sudah
diputuskan eksplisit oleh operator lewat pertanyaan pilihan sebelum
brief ditulis — bukan devs berimprovisasi, dicatat di sini biar tidak
di-re-litigasi sesi berikutnya.

**Verifikasi:** Diuji end-to-end langsung di browser (desktop & mobile
375px): pilih difficulty → mulai → jawab benar (skor naik proporsional
ke kecepatan, opsi benar di-highlight hijau) → jawab salah (skor tidak
berubah, opsi salah merah + opsi benar tetap hijau) → biarkan timer
habis tanpa klik (bar habis penuh, opsi benar ter-highlight otomatis,
skor tidak berubah) → lanjut sampai soal ke-10 → overlay hasil akhir
tampil benar (skor total + breakdown "X dari 10") → "Main Lagi" reset
balik ke panel start dengan benar. Landing page (`games/index.php`)
dicek ulang — ketiga card sekarang tampil "MAIN SEKARANG" dengan accent
masing-masing (orange/cyan/purple). `php -l` bersih di
`games/quiz-bola/index.php` dan `games/index.php`; brace/paren JS &
CSS balanced. Beda dari 2 entri Penalty Kick sebelumnya: game ini TIDAK
butuh monkey-patch `requestAnimationFrame`→`setTimeout` buat testing —
karena render-nya DOM/CSS-driven (bukan Canvas redraw loop), animasi
timer & transisi state semuanya jalan normal di environment testing
sesi devs ini walau `document.hidden` masih `true` di sana.

**Alternatif yang dipertimbangkan:** Skor eksponensial/step-based
(bukan linear falloff) — ditolak, linear paling gampang diimplementasi
dan dipahami ulang, insentif "cepat = lebih banyak poin" tetap terasa
tanpa perlu tuning kurva. Variasi soal per level kesulitan (disebut
opsional di brief) — tidak dikerjakan, brief eksplisit bilang jangan
sampai bikin brief ini membengkak jadi 3x kerjaan; kalau operator mau
soal per-level yang beda kontennya, itu scope brief terpisah.

---

## 2026-09-03 — Games Hub: game keempat, Slot Bola (TANPA sistem uang/kredit)

**Keputusan:** Game keempat ditambahkan, di luar rencana 3-slot awal
(Air Hockey/Penalty Kick/Kuis Bola) — diminta langsung operator sebagai
brief baru. Slug `slot-bola`, mesin slot 3x3 bertema sepak bola.

**Yang paling penting di entri ini — batasan hukum/kepatutan, BUKAN
preferensi kosmetik, JANGAN di-re-litigasi:** Operator ditanya eksplisit
apakah game ini pakai sistem uang/kredit/koin yang bisa habis+diisi
ulang (berpotensi terbaca sebagai judi), atau murni hiburan tanpa
reward finansial. Operator memilih **murni hiburan, TANPA APAPUN**:
tidak ada "beli koin"/"top up"/"deposit"/"withdraw", tidak ada
representasi uang asli maupun virtual currency yang bisa dibeli, tidak
ada kredit yang habis lalu memblokir main lagi. Spin gratis & unlimited
selamanya — sama level abstraksi dengan skor Air Hockey/Kuis Bola:
counter lokal in-session, reset saat reload, tidak pernah ditukar
apapun di luar game, tidak ada leaderboard yang menjanjikan sesuatu.
Kalau ada sesi/devs berikutnya berpikir untuk menambahkan mekanisme
"chip"/"lives"/"energy" yang harus di-refill — itu masuk kategori yang
sama dan TIDAK BOLEH tanpa keputusan operator baru yang eksplisit.

File baru: `games/slot-bola/index.php`, `assets/games/css/slot-bola.css`,
`assets/games/js/slot-bola.js`. File modified: `games/index.php`
(nambah 1 entry baru `slot-bola` di `$wpmGames`, 3 entry lain tidak
disentuh), `assets/games/css/games-landing.css` (nambah 1 accent
variant baru `.wpm-game-card--gold`, rule lain tidak disentuh).

1. **Gameplay: slot 3x3, 3 payline horizontal (atas/tengah/bawah)** —
   tidak ada payline diagonal di versi ini (brief eksplisit bilang itu
   "versi 2" kalau diminta nanti). 7 simbol via emoji Unicode (0 KB
   tambahan, pola sama dengan pendekatan zero-image-asset di Kuis
   Bola): 🟨 Kartu Kuning, 👟 Sepatu, 🟥 Kartu Merah, 🥅 Gawang, ⚽
   Bola, 🏆 Trofi (payout tertinggi), ⭐ Wild (cocok simbol apapun,
   3 wild sebaris = jackpot 1500 poin, nilai terpisah dari payout
   simbol biasa).
2. **Weighted random, bukan uniform** — `weightedPick()` di
   `slot-bola.js`, bobot per simbol: Kartu Kuning 30, Sepatu 24, Kartu
   Merah 18, Gawang 13, Bola 8, Trofi 4, Wild 3 (total 100) — simbol
   umum jauh lebih sering muncul dibanding simbol langka, sesuai
   standar slot machine ("kadang menang kecil, jarang menang besar").
   Playtest manual devs: dari ~20 spin, mayoritas kalah/menang kecil
   (kartu kuning/sepatu), beberapa menang menengah (gawang/kartu
   merah), 0 jackpot dalam sampel kecil itu — rasanya wajar, tidak
   "hampir selalu kalah" atau "hampir selalu menang".
3. **Animasi reel: DOM+CSS, bukan Canvas** — sama pendekatan dengan
   Kuis Bola. Tiap reel adalah viewport `overflow:hidden` + strip
   vertikal (`.wpm-sb-reel__strip`) berisi simbol filler (weighted
   random, kosmetik doang, tidak pernah dievaluasi menang/kalah) +
   3 simbol final (hasil sebenarnya, di-generate SEBELUM animasi
   mulai — `spin()` pre-roll semua hasil dulu, animasi murni kosmetik
   yang menampilkan hasil itu). `strip.style.transform` di-transisi
   CSS ke offset yang pas nempatkan 3 simbol final di viewport;
   masing-masing reel durasi beda (900ms/1250ms/1650ms) biar berhenti
   satu-satu kayak slot asli (bukan `transition-delay`, cukup durasi
   beda + start bareng, lebih simpel). Height sel diukur runtime lewat
   `getBoundingClientRect()` (bukan hardcode px) biar tetap akurat di
   breakpoint mobile/desktop CSS yang beda ukuran selnya — sempat ada
   sliver 1-3px baris di atasnya "nyembul" dikit di viewport mobile
   karena nilai float dari `getBoundingClientRect()`, di-fix dengan
   `Math.ceil()` pas ngukur cellHeight.
4. **Payline win-highlight + audio** — baris yang menang di-highlight
   pakai border/glow hijau (`.wpm-sb-payline.is-win`, elemen overlay
   terpisah per baris, `pointer-events:none` biar tidak nutupin klik).
   Audio: pola sintesis nada Web Audio yang sama persis (copy ulang,
   bukan import — tetap self-contained per file) dengan 3 game
   sebelumnya, cue baru: `spin` (whoosh naik), `reelStop` (blip pendek
   tiap reel berhenti), `win` (4-note naik), `jackpot` (5-note lebih
   panjang), `noWin` (nada rendah pendek). Mute button di topbar, pola
   sama.
5. **Tidak ada selector Easy/Medium/Hard** — pengecualian yang
   disengaja dari brief: konsep "kesulitan" tidak natural buat mesin
   slot murni acak (beda dari 3 game lain yang punya lawan
   AI/timer-tunable). Tidak dipaksa nambahin selector kosong cuma buat
   konsisten visual.
6. **Icon fallback, bukan logo custom** — `logo: null` di entry
   `slot-bola` (belum ada artwork PNG custom), pakai `icon: 'football'`
   (dicek dulu di `wpm_icon()` — `includes/site-bootstrap.php` — bukan
   nama yang ditebak, memang tersedia). Operator bisa minta logo custom
   sebagai revisi terpisah nanti, sama pola yang dipakai `quiz-bola`
   sebelum logo PNG-nya jadi.
7. **Accent baru: gold** (`#ffd23f` / `255,210,63`) — ditambahkan ke
   `games-landing.css` sebagai `.wpm-game-card--gold`, sengaja dibuat
   lebih kuning-jenuh dibanding `--orange` yang sudah dipakai Air
   Hockey (`#ffc247`, condong ke amber) biar 4 card di landing tetap
   gampang dibedakan sekilas. Warna yang sama (didefinisikan lokal,
   bukan baca custom property card) dipakai juga buat nuansa UI di
   dalam halaman game (`slot-bola.css`'s `--sb-gold`).

**Alasan:** Semua keputusan gameplay (no-money constraint, weighted
random, 3 payline horizontal saja, tanpa level selector) sudah
diputuskan eksplisit oleh operator sebelum brief ditulis — dicatat di
sini terutama poin no-money karena ini yang paling penting untuk tidak
salah dibaca sesi/devs berikutnya.

**Verifikasi:** Diuji langsung di browser (desktop & mobile 375px) —
`grep` di seluruh file baru buat memastikan tidak ada istilah
kredit/koin/deposit/withdraw/saldo yang mengarah ke implementasi
sistem uang (hasil grep cuma nemu comment/teks yang secara eksplisit
MENOLAK sistem itu, bukan mengimplementasikannya). Alur spin diuji
lewat DOM/JS langsung (bukan cuma baca screenshot — emoji tertentu
seperti 🥅/👟 render beda di browser testing environment ini, jadi
verifikasi weighted-pick & win-evaluation dilakukan dengan membaca
`textContent` sel asli lewat JS, bukan menebak dari piksel screenshot)
— dikonfirmasi: kombinasi 3 simbol sama sebaris = menang + payline
highlight + skor nambah sesuai poin simbol; kombinasi campur wild +
1 simbol lain = tetap menang (wild-substitution jalan benar); 3 simbol
beda = tidak menang, skor tidak berubah. Skor akumulasi across multiple
spins dikonfirmasi lewat loop otomatis 6x spin sampai dapat kombinasi
menang. `php -l` bersih di `games/slot-bola/index.php` dan
`games/index.php`; brace/paren JS & CSS balanced (termasuk
`games-landing.css` setelah nambah accent gold). Landing page dicek
ulang — 4 card sekarang tampil, `slot-bola` dengan accent gold yang
jelas beda dari orange-nya Air Hockey.

**Alternatif yang dipertimbangkan:** Payline diagonal — tidak
dikerjakan, brief eksplisit bilang itu "versi 2". Bank simbol beda per
level kesulitan — tidak relevan, game ini sengaja tanpa level selector
(lihat poin 5). Auto-spin/spin berulang otomatis — tidak diminta brief
dan berisiko terbaca mendekati pola mekanisme "gambling-like"
(spin cepat berulang tanpa interaksi eksplisit user tiap kali), jadi
sengaja tidak ditambahkan meski secara teknis gampang — tetap 1 klik =
1 spin, konsisten dengan batasan "murni hiburan" di atas.

---

## 2026-09-05 — Slot Bola v2: payline diagonal, streak, rank, bonus round

**Keputusan:** Lanjutan langsung dari entri "Slot Bola" (3 Sep 2026)
di atas — belum di-deploy ke production, operator minta gameplay-nya
diperdalam sebelum naik ("belum jadi juga, masih terlalu simple").
Semua 6 fitur di brief "Slot Bola v2 — Perdalam Gameplay" (5 Sep 2026)
dikerjakan dalam 1 pass (bukan fase bertahap v2a/v2b) karena semuanya
di file yang sama dan salinan file relevan (index.php/css/js) semua
sudah ada tinggal di-extend, bukan ditulis ulang dari nol.

**Batasan "TANPA UANG/KREDIT/KOIN" dari entri 3 Sep 2026 di atas TETAP
BERLAKU PENUH untuk semua fitur baru ini** — diverifikasi eksplisit per
fitur sebelum dikerjakan:
- Multiplier streak, rank, dan bonus round semuanya murni turunan dari
  `score` (counter lokal in-session yang SAMA, bukan counter baru) —
  tidak ada balance/credit/coin terpisah yang bisa "habis".
- Bonus round CUMA nambah 3 auto-spin GRATIS ekstra, bukan mekanisme
  baru yang membatasi/mensyaratkan apa pun sebelum spin — spin di luar
  bonus round juga tetap gratis tanpa syarat seperti sebelumnya.
- Tidak ada localStorage/persistence baru ditambahkan — semua state v2
  (`winStreak`, `currentRankIndex`, achievement flags) reset ke 0/false
  saat reload, sama seperti `score` v1.
- `grep` ulang di seluruh file yang disentuh untuk kata kunci
  kredit/koin/deposit/withdraw/saldo — hasil cuma comment/teks
  disclaimer yang MENOLAK sistem itu, sama seperti verifikasi v1,
  tidak ada implementasi baru yang melanggar.

1. **Payline diagonal (prioritas tinggi)** — `PAYLINES` array baru di
   `slot-bola.js`, 5 line total (3 horizontal + 2 diagonal: kiri-atas→
   kanan-bawah, kiri-bawah→kanan-atas), semuanya dievaluasi lewat
   `evaluateLine()` yang sama (rename dari `evaluateRow()` — logic win/
   wild/jackpot tidak berubah, cuma generalized dari "baris" ke "line
   sembarang bentuk"). 2 elemen overlay baru di `index.php`
   (`#sb-payline-3`/`#sb-payline-4`, class `.wpm-sb-payline--diag`) —
   lebar & rotasinya DIHITUNG RUNTIME di `layoutDiagonalPaylines()`
   (trigonometri dari `getBoundingClientRect()` grid reel, bukan sudut
   CSS hardcode) karena grid 3x3-nya tidak persegi sempurna di semua
   breakpoint. `resultEl` menyebut jenis line yang menang (horizontal/
   diagonal/gabungan) — diverifikasi lewat spin diagonal nyata di
   mobile 375px, garis overlay pas persis melewati 3 sel yang menang.
2. **Multiplier streak** — `winStreak` naik tiap spin menang
   (`totalPoints>0`), reset ke 0 tiap kalah. `streakMultiplier()`:
   streak1=x1, streak2=x1.2, streak3=x1.5, streak4+=x2 (cap). Dikali ke
   `totalPoints` SEBELUM ditambah ke `score` (`finalPoints =
   Math.round(totalPoints*multiplier)`), sesuai urutan yang diminta
   brief. Badge `#sb-streak-badge` di scoreboard, hidden kalau
   streak=0. Diverifikasi lewat spin ter-kontrol (RNG di-mock lewat
   `Math.random` override saat testing, BUKAN kode production) yang
   memastikan urutan streak 1→2 menghasilkan skor +240 dari raw 200
   poin (x1.2) persis sesuai formula.
3. **Rank progresif** — `RANKS` array persis seperti contoh brief (5
   tingkat, ambang 0/2000/6000/15000/35000). Badge permanen
   `#sb-rank-badge` di scoreboard, update tiap `resolveSpin()`.
   Rank-up (skor lewat ambang baru) memicu pulse/glow CSS
   (`.is-leveling-up`, `@keyframes wpmSbRankPulse`) + 1 tone
   (`sfx.rankUp()`) + toast "🎖️ Naik rank: {nama}!" — bukan full
   confetti (brief eksplisit minta versi lebih kalem dibanding flourish
   Kuis Bola). Diverifikasi lewat spin yang di-mock buat 100% Wild (7500
   poin, langsung lompat dari "Pemain Amatir" ke "Semi Pro" dalam 1
   spin) — badge, pulse, DAN toast semuanya kekonfirmasi jalan
   bersamaan lewat `MutationObserver` di browser test, bukan cuma baca
   screenshot.
4. **Mini free-spin bonus round** — simbol baru `🎫 Tiket Bonus`
   (weight 2, points 0) ditambah ke `SYMBOLS`. `checkBonusTrigger()`
   cek 3 tiket di baris (row) yang sama — SENGAJA cuma cek 3 baris lurus
   (bukan diagonal), literal sesuai brief ("muncul di ketiga reel
   sekaligus di baris yang sama"). Trigger → `startBonusRound()`:
   banner "🎉 BONUS ROUND! 3 Spin Gratis" muncul, `inBonusRound=true`,
   auto-chain TEPAT 3 spin tambahan (`bonusSpinsRemaining`, di-drive
   lewat `setTimeout` dari akhir `resolveSpin()` masing-masing, bukan
   loop `for` — supaya reel animation tiap spin bonus tetap muncul
   utuh, bukan langsung lompat ke hasil akhir), tombol Spin disabled
   selama sequence biar user tidak nge-klik di tengah auto-spin. Selama
   bonus round, `weightedPick()` baca dari `BONUS_TABLE` (weight Bola/
   Trofi/Wild di-2x-kan, disiapkan sebagai tabel weighted TERPISAH biar
   `NORMAL_TABLE` tidak pernah dimutasi) — bukan symbol/reward baru,
   cuma odds sementara yang lebih murah hoki, sesuai brief. Retrigger
   selama bonus round sedang aktif SENGAJA diabaikan (guard
   `!inBonusRound` di titik trigger) — dipertimbangkan sebagai
   penyederhanaan MVP, bukan reject permanen; kalau operator mau nested
   bonus round itu perlu keputusan/brief terpisah. Diverifikasi lewat
   spin yang di-mock 3 tiket sebaris — banner muncul & hilang dengan
   timing yang sesuai 3 spin berturut (~7.9 detik total, cocok dengan
   3× durasi reel + jeda antar-spin), tombol Spin ke-disable sepanjang
   sequence lalu ke-enable lagi setelah selesai.
5. **Near-miss feedback** — `evaluateLine()` sekarang juga return
   `nearMiss: true` kalau bukan win TAPI ada tepat 2 dari 3 simbol
   mentah yang sama (implementasi: hitung kemunculan tiap nama simbol
   di line, near-miss kalau ada tepat 1 nama yang muncul 2x dari 2 nama
   berbeda total — kasus yang melibatkan wild otomatis sudah ke-cover
   sebagai WIN duluan di cabang di atas, jadi tidak pernah nyampe ke
   near-miss check, sesuai maksud brief). Reel yang "meleset" (posisi
   simbol ganjil) di-shake 0.4s (`@keyframes wpmSbNearMissShake`) +
   1 tone beda (`sfx.nearMiss()`) dari `sfx.noWin()` biasa — murni
   kosmetik, skor tidak berubah. Diverifikasi lewat `MutationObserver`
   yang mengonfirmasi class `.is-near-miss` benar-benar sempat muncul
   (class-nya di-auto-remove ~450ms sesudahnya, jadi screenshot biasa
   sering ketinggalan momennya — testing pertama sempat false-negative
   karena ini sebelum ganti ke `MutationObserver`).
6. **Achievement toast session-only** — 2 flag in-memory
   (`achievedStreak3`, `achievedFirstJackpot`), masing-masing cuma bisa
   trigger toast SEKALI per sesi (reset ke false saat reload, tidak
   pernah disimpan). "🔥 Menang 3x Beruntun!" dites lewat 3 spin
   menang berturut (mock RNG), "🎰 Jackpot Pertama!" dites bareng
   rank-up test di poin 3 — dua-duanya kekonfirmasi muncul di
   `#sb-toast-wrap` lewat observer yang sama.

**Alasan:** Semua 6 fitur diminta eksplisit di brief dengan spesifikasi
yang cukup detail (termasuk contoh angka RANKS/multiplier/weight) untuk
dikerjakan langsung dalam 1 pass tanpa perlu klarifikasi tambahan;
tidak ada titik yang "goyah" antara fitur vs representasi uang (bagian
"ATURAN KERAS" brief secara eksplisit minta STOP-and-ask kalau ada yang
begitu — tidak ditemukan, semua fitur murni skor/label lokal).

**Verifikasi:** `node -c` TIDAK BISA dijalankan — tidak ada `node` di
host maupun di semua container Docker project ini (`php8_apache`,
`php8x_apache`, `php5_apache`, `mysql_db`, `phpmyadmin`, sudah dicek
satu-satu). Sebagai gantinya: brace/paren/bracket balance check via
Python (146/146 brace, 368/368 paren, 66/66 bracket — balanced),
`php -l` bersih di `games/slot-bola/index.php`, DAN yang paling
penting — testing end-to-end nyata di browser dengan `Math.random`
di-override sementara (testing-only, TIDAK ada di kode production,
pola sama seperti monkey-patch rAF di entri-entri Penalty Kick
sebelumnya) buat memaksa kombinasi spesifik (win horizontal/diagonal/
near-miss/jackpot/bonus-trigger) yang kalau ditunggu RNG asli bisa
butuh ratusan spin karena beberapa simbolnya sengaja langka. Semua 6
fitur dikonfirmasi jalan lewat browser test nyata (bukan cuma review
kode), termasuk kombinasi majemuk (jackpot + rank-up + achievement
toast bersamaan dalam 1 spin). `grep` money-term di semua file yang
disentuh (`games/slot-bola/index.php`, `assets/games/css/slot-bola.css`,
`assets/games/js/slot-bola.js`) — hasil cuma comment/disclaimer,
konsisten dengan verifikasi v1.

**Alternatif yang dipertimbangkan:** Bonus round pakai `for` loop
sinkron buat 3 spin — ditolak, itu akan skip animasi reel tiap spin
bonus (langsung lompat ke hasil), pengalaman kurang berasa "spin".
Dipilih chaining lewat `setTimeout` dari akhir tiap `resolveSpin()`,
sama sekali tidak mengubah `spin()`/`spinReel()` yang sudah ada. Cek
bonus-trigger di 5 payline (termasuk diagonal) — ditolak, brief bilang
literal "baris" (row), diagonal sengaja dikecualikan biar tidak ambigu.
Belum di-deploy — nunggu review/approve operator dulu setelah cek diff,
sesuai instruksi.

---

## 2026-09-08 — Penalty Kick: mode gantian penuh + fix visual keeper

**Keputusan:** Brief teknis operator lewat sesi ini, 2 perubahan ke
Penalty Kick: (1) mode shootout beneran — user & CPU gantian jadi
penendang/kiper tiap kick (dulu: user selalu nendang 5x, CPU selalu
jaga gawang), (2) fix visual — keeper yang tadinya "gantung ditengah"
(stick figure tipis) + warnanya harus ngikut tim yang dipilih user,
bukan selalu gold.

Sesi ini melanjutkan progress PARSIAL yang sudah ada di kode sebelum
brief ini ditulis (TEAMS.color, CPU_COLOR/playerColor(), resetShootout()
dengan state.round/turn/playerGoals/cpuGoals, pickCpuShotZone(),
attemptAction() rename, updateScoreboard()) — pekerjaan yang BELUM
kelar (logic pindah giliran di loop(), parameter warna dinamis di
drawKeeper()/drawKicker(), HTML scoreboard label + elemen turn-banner,
CSS-nya) diselesaikan di sesi ini persis sesuai spesifikasi tertulis di
brief.

1. **`loop()`'s `'resolved'` phase ditulis ulang total** — logic lama
   (`if (state.shotsTaken >= TOTAL_SHOTS) { endShootout(); }` single-
   sided) diganti alur gantian: kalau `state.turn === 'player'` baru
   selesai nendang → giliran pindah ke `'cpu'`, `pickCpuShotZone()`
   dipanggil SEKARANG (sebelum player mulai jaga gawang) buat nentuin
   arah tembakan CPU; kalau CPU baru selesai nendang → `state.round++`,
   cek `> totalRounds` buat `endShootout()`, giliran balik ke
   `'player'`. Tally skor gol di phase `'animating'` juga diubah dari
   `state.goals++` (counter lama, single-side) jadi
   `state.playerGoals++`/`state.cpuGoals++` tergantung `state.turn`.
   `TOTAL_SHOTS` (var lama, sudah tidak dipakai sama sekali setelah
   perubahan ini) dihapus.
2. **`drawKeeper(x,y)` dan `drawKicker()` sekarang terima parameter
   `color`** (fallback ke warna lama kalau dipanggil tanpa argumen) —
   `draw()` sekarang hitung `kickerColor`/`keeperColor` tiap frame dari
   `state.turn`: figure PLAYER (baik pas nendang maupun pas jaga
   gawang) selalu `playerColor()` (warna tim yang dipilih di layar
   pilih timnas), figure CPU selalu `CPU_COLOR` (`#ff3d5a`, fixed).
   Diverifikasi lewat spin/kick nyata: keeper merah (CPU) pas giliran
   player nendang, keeper biru-muda Argentina (warna tim yang dipilih)
   pas giliran CPU nendang — warna ke-swap dengan benar tiap kali
   giliran ganti.
3. **Fix visual "gantung ditengah"** — awalnya cuma dinaikin skala
   (~18%, headR/torsoLen/legLen) + torso solid fill + ground shadow
   lebih besar/gelap, sesuai draft awal brief. **Operator kasih
   feedback tambahan mid-sesi** (screenshot referensi game "Penalty
   Shooters 2" yang pakai sprite/ilustrasi 2D asli, minta visual lebih
   "leih/lebih") — ditanya balik apakah mau pindah ke sprite gambar
   asli atau tetap Canvas/vector didorong lebih jauh; operator pilih
   **tetap Canvas, didorong lebih jauh** (bukan sprite gambar) — sesuai
   pola project ini yang konsisten zero-image-asset dari awal (lihat
   entri-entri Games Hub sebelumnya). Hasil: `drawKeeper()`/
   `drawKicker()` sekarang punya "kit" berlapis — kaos/jersey (blok
   solid, sudah ada), celana pendek (blok baru, warna diturunkan dari
   `color` via `shadeColor()` fungsi baru — helper darken/lighten hex
   sederhana), kaos kaki (stroke tebal, warna diturunkan lagi lebih
   gelap dari celana), sepatu (elips kecil gelap di ujung kaki), dan
   khusus keeper: sarung tangan (lingkaran kecil putih di ujung
   tangan). Semua tetap 100% Canvas API native, 0 aset gambar baru,
   payload nggak nambah sama sekali.
4. **HTML (`games/penalty-kick/index.php`)** — label scoreboard
   `#pk-shot-count` diubah dari "Tendangan" jadi "Ronde" (JS sekarang
   isi `round/totalRounds`), label `#pk-goal-count` dari "Gol" jadi
   "Skor" (JS sekarang isi format `playerGoals - cpuGoals`). Elemen
   baru `#pk-turn-banner` ditambah di dalam `#pk-board`, sebelum
   `.wpm-pk-scoreboard` — teks & class (`--kick`/`--keep`) di-drive
   `updateScoreboard()` yang sudah ada di JS. Hint text di panel
   start (`#pk-team-hint`, di-override lagi oleh `selectTeam()`) dan
   default HTML-nya diupdate jelasin mode gantian, bukan lagi "5
   tendangan cetak gol sebanyak mungkin".
5. **CSS (`assets/games/css/penalty-kick.css`)** — style
   `.wpm-pk-turn-banner`/`--kick`/`--keep` ditambah, PAKAI CSS var yang
   sudah ada (`--ah-neon-green`/`--ah-neon-pink` dari games-landing.css)
   bukan hex baru, biar konsisten sama palet yang sudah dipakai di
   seluruh product family ini.

**Alasan:** Kedua perubahan diminta eksplisit operator lewat brief
tertulis; poin visual (kit berlapis) diperluas mid-sesi setelah
operator kasih referensi visual tambahan — ditanya dulu lewat
`AskUserQuestion` (sprite gambar vs Canvas didorong lebih jauh)
sebelum dikerjakan, bukan diasumsikan sepihak, karena pindah ke sprite
gambar akan bertentangan langsung sama keputusan zero-image-asset yang
sudah berulang kali dikonfirmasi di project ini.

**Verifikasi:** `php -l` bersih, brace/paren JS & CSS balanced. Diuji
end-to-end nyata di browser (bukan cuma baca kode) — sempat ada
kebingungan awal saat testing karena environment browser pane sesi
devs ini (`document.hidden` true) bikin timing `setTimeout`-based
loop (monkey-patch testing-only pengganti `requestAnimationFrame`,
SAMA SEKALI tidak ada di kode production) jadi tidak konsisten kalau
di-poll terlalu cepat/berturut-turut tanpa nunggu — begitu diuji pakai
polling yang nunggu perubahan state (bukan `sleep` tetap), 1 klik
terbukti konsisten cuma resolve 1 giliran (bukan double-resolve),
dikonfirmasi lewat siklus penuh 5 ronde (10 kali kick total) sampai
`endShootout()` — hasil akhir "Kamu 4 - 3 CPU" / "Kamu Menang! 🏆"
match persis sama akumulasi skor per-giliran yang di-log. Dites juga di
mobile (375px) — figure kicker & keeper tampil benar dengan kit
berlapis yang baru, "Main Lagi" reset state (ronde/skor/giliran) dengan
benar, tidak ada error console sepanjang testing.

**Alternatif yang dipertimbangkan:** Sprite/gambar ilustrasi asli
(referensi "Penalty Shooters 2") — ditawarkan eksplisit ke operator,
DITOLAK (operator pilih tetap Canvas/vector) karena akan nambah
payload + butuh sourcing aset dengan lisensi jelas + keluar dari pola
zero-image-asset yang sudah jadi keputusan project berulang kali sejak
MVP — kalau operator berubah pikiran nanti, itu keputusan baru yang
perlu dicatat terpisah, bukan devs berinisiatif sendiri pindah
pendekatan.
