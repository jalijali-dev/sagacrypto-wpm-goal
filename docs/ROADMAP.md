# SagaCrypto / WPM — Roadmap

> Peta prioritas kerja project ini. Untuk peta route/menu lengkap dan riwayat
> perubahan detail per-tanggal, lihat `SITEMAP.md` (root). Untuk konteks
> cepat & konvensi teknis, lihat `HANDOFF.md` (root) dan `docs/DEV_GUIDE.md`.

Terakhir diperbarui: **31 Juli 2026** (setup backup otomatis mingguan ke Google Drive)

---

## Legend status

| Status | Arti |
|---|---|
| 🔴 Blocked | Tidak bisa lanjut tanpa input/aksi dari luar (biasanya eksekusi manual oleh user — sandbox tidak punya akses DB/git live) |
| 🟡 In Progress | Sedang dikerjakan sekarang |
| 🟢 Ready | Sudah jelas scope-nya, siap dikerjakan, belum mulai |
| ⏸️ On Hold | Sengaja ditunda atas keputusan user — bukan diblokir, bukan prioritas saat ini |
| ✅ Done | Selesai — diarsipkan ringkas di bagian "Done" di bawah, dengan tanggal |

---

## Now

Prioritas berjalan / yang paling butuh perhatian saat ini.

- ✅ **Selesai (dikonfirmasi user 28 Jul 2026)** — 3 migrasi SQL destructive
  (`008_remove_products.sql`, `012_cleanup_unused_tables_columns.sql`,
  `013_remove_livescore_module.sql`) sudah dijalankan manual ke database
  live oleh user. File migrasinya sendiri sudah tidak ada di working
  directory (terhapus di salah satu commit lama "cleanup modul lama") —
  ini sesuai ekspektasi, bukan kehilangan yang tidak disengaja.
- ✅ **Selesai (28 Jul 2026)** — Git commit, push, dan deploy ke production
  lengkap. Ternyata `origin/main` sebelumnya punya history 22 commit yang
  sudah tidak nyambung sama sekali sama `main` lokal (unrelated histories,
  bukan cuma "ketinggalan beberapa commit") — dikonfirmasi user, diselesaikan
  dengan force-push (`git push origin main --force`) supaya versi Growth
  Agent yang sudah ditest end-to-end sepanjang sesi ini (commit `a03057c`)
  yang jadi source of truth di GitHub. Repo di cPanel
  (`/home/sagagoal/repositories/sagacrypto-wpm-goal`) sempat kehilangan
  referensi branch valid akibat force-push ("No checked-out branch is
  available") — diperbaiki dengan remove + clone ulang. Ke-44 file yang
  berubah sudah di-`cp` selective ke `public_html` dan diverifikasi identik
  lewat `diff -rq` per folder — nol perbedaan di luar file yang memang
  gak seharusnya ikut (`cgi-bin`, `data`, `.htaccess` khusus `cms-admin`).
  Detail lengkap troubleshooting (git lock, force-push, branch cPanel
  nyangkut, isi `.cpanel.yml`) sudah didokumentasikan di
  `docs/DEPLOY_WORKFLOW.md` buat referensi deploy berikutnya.
- ✅ **Selesai (28 Jul 2026)** — UI admin Growth Agent (5 halaman:
  `growth-agent.php`, `gsc-settings.php`, `indexing-issue-review.php`,
  `cannibalization-review.php`, `seo-recommendation-review.php`)
  diterjemahkan penuh ke Bahasa Indonesia, plus tombol "Refresh Data" di
  `growth-agent.php` di-rename jadi "🔄 Fetch GSC Data" (emoji, bukan
  Font Awesome — codebase ini belum pakai FA sama sekali). Sengaja
  dibiarkan tetap Inggris atas keputusan user: tombol aksi utama
  Approve/Reject/Generate/Apply (dianggap sudah familiar buat admin).
  Commit `7931229`, di-deploy ke production via alur 3-langkah standar
  (`docs/DEPLOY_WORKFLOW.md`) — `cp` selective ke 5 file di atas,
  diverifikasi `diff` kosong semua.
- ✅ **Selesai (28 Jul 2026)** — 2 panel dashboard baru di
  `growth-agent.php`: **Halaman Teratas** (breakdown GSC per-artikel —
  klik/impresi/CTR/posisi, dari `gsc_query_data` di-`GROUP BY page_url`,
  di dalam guard `$gscConnected` sama seperti Top Queries) dan **Artikel
  Terpopuler** (total views sepanjang waktu per artikel, dari kolom
  `pages.views` yang sudah lama jalan lewat `wpm_increment_views()` —
  sengaja diletakkan di LUAR guard `$gscConnected` karena tidak
  bergantung ke GSC sama sekali). Tidak ada tabel/kolom DB baru, murni
  `SELECT` read-only tanpa tombol aksi. Diverifikasi kode + query
  langsung ke database live (bukan cuma lint) sebelum deploy.

- ✅ **Selesai (28 Jul 2026)** — Modul baru **SEO Intelligence** (Topic
  Cluster + Content Conflict Detection), ditaruh di grup AI Management di
  bawah GSC Settings. Dua tabel baru (`growth_agent_topic_clusters`,
  `growth_agent_content_conflicts`, lazy auto-create). Trigger manual
  doang (tombol "Generate"), batch dibatasi ke 50 artikel published
  terbaru per generate (title+meta_description, bukan full content).
  Topic Cluster: AI kelompokkan artikel jadi cluster, pilih pillar,
  tandai cluster yang butuh konten tambahan + saran topik yang kurang.
  Content Conflict Detection: AI cari pasangan artikel yang search
  intent-nya kemiripan tinggi (beda dari Cannibalization Review yang
  udah ada — itu murni dari data klik/impresi GSC asli, ini heuristik
  dari kemiripan metadata artikel). Guardrail "Recommendation only"
  dijaga penuh: "Generate Saran Artikel"/"Buat Proposal Konflik" cuma
  bikin job `manual_action` baru di antrian review, approve untuk topic
  gap article baru bikin draft artikel (`status='draft'`), approve untuk
  conflict proposal TIDAK PERNAH mengubah/merge/redirect artikel apapun
  — cuma menandai sudah ditinjau manusia. Slug dari AI di-resolve balik
  ke `page_id` cuma dari daftar 50 artikel yang memang dikirim di
  prompt (anti-halusinasi). Diverifikasi lewat pembacaan kode langsung.

## Next

Antrian berikutnya setelah item "Now" selesai.

### Growth Agent / SEO — lanjutan sesuai `docs/GROWTH_AGENT_SEO_ROADMAP.md`

Growth Agent + integrasi GSC **sudah jauh lebih matang dari yang terlihat**
(di-*port* 27 Jul 2026 dari project sibling `wpm.sagacrypto.com`, lihat "Done"
di bawah). Dibandingkan MVP 5-item yang disarankan
`docs/GROWTH_AGENT_SEO_ROADMAP.md`, status per item:

| Item MVP | Status |
|---|---|
| 1. GSC Collector | ✅ Selesai — service account JWT flow, lazy fetch-if-stale, error log |
| 2. Opportunity Engine (deterministik) | ✅ Selesai — semua kategori (termasuk Cannibalization + Content Decay, gap #5, 28 Jul 2026), scoring berbasis threshold config (`gsc_settings.opportunity_thresholds_json`), dedupe by hash |
| 3. Growth Agent (evidence → rekomendasi) | ✅ Selesai — 3 job type: `seo_recommendation`, `gsc_content_optimization`, `gsc_article_idea` |
| 4. Action Queue + approval | ✅ Selesai — `growth_agent_jobs` (ready/running/succeeded/failed/manual_action/closed_as_legacy) + feedback approve/reject |
| 5. Content Agent Adapter (draft ke CMS) | ✅ Selesai (27 Jul 2026) — lihat "Done" di bawah |

**Status: semua gap yang tercatat di roadmap ini sudah selesai (28 Jul
2026).** Growth Agent MVP 5-item + seluruh kategori Opportunity Engine yang
disebut di `docs/GROWTH_AGENT_SEO_ROADMAP.md` (Low CTR, Near page one,
Zero-click, Content gap/No article, Indexing issue, Content Decay,
Cannibalization) sekarang tercakup — lihat "Done" di bawah untuk detail
teknis tiap gap (#1 Content Agent Adapter, #2 Indexing Workflow, #3 Agent
Memory, #4 Feedback Loop, #5 Cannibalization + Content Decay). Tidak ada
item baru yang menunggu di section ini — kalau ada follow-up (tuning
threshold, kategori baru di luar yang disebutkan di roadmap, dst.), catat
sebagai item baru di sini saat itu terjadi, jangan asumsikan otomatis
lanjut dari sini.

Belum masuk prioritas sama sekali (sesuai bagian "Tidak diperlukan" di
`GROWTH_AGENT_SEO_ROADMAP.md`, dan memang bukan goal kita): Social Agent,
CRM/lead agent, competitor scraping, autonomous publishing, embeddings/vector
DB.

### Growth Agent v2 — hasil analisis gap (disetujui 1 Agu 2026)

Dipicu dari perbandingan alur Growth Agent Sagagoal sama diagram
referensi eksternal ("Val's Cake") + pertanyaan: workflow apa yang
paling efektif buat naikin artikel & SEO ke page 1 Google. Detail
teknis lengkap (skema DB, alasan tiap item, urutan prioritas) ada di
`docs/GROWTH_AGENT_V2_PROPOSAL.md` — ringkasan di bawah ini cuma index,
jangan diedit terpisah dari dokumen aslinya.

**Belum ada satu item pun yang mulai dikerjakan** — status "disetujui"
di sini artinya masuk antrian resmi, prioritas urutan fase A → D.

- **Fase A — Fondasi:** scheduler mandiri (Cron Job cPanel, pola sama
  kayak backup mingguan di `docs/BACKUP_WORKFLOW.md`, gantikan trigger
  "lazy" yang sekarang cuma jalan pas admin buka halaman), notifikasi
  digest mingguan (Telegram/email), SEO-G0 Gate diformalkan (Content
  Conflict Detection yang sudah ada dipindah jadi pre-check sebelum
  agent lain bikin usulan, bukan kategori opportunity terpisah).
- **Fase B — Akselerator ranking:** 3 karakter baru — **Internal Linking
  Agent** (saran link antar artikel, approve → masuk draft revisi),
  **Keyword Expansion Agent** (usulan topik/keyword di luar histori GSC,
  nyambung ke Topic Cluster yang sudah ada), **Technical SEO Auditor**
  (Core Web Vitals via PageSpeed Insights API, schema markup, alt text
  — laporan doang, gak auto-fix).
- **Fase C — Distribusi & closing the loop:** **Social Specialist**
  (siapin draft caption sosmed setelah artikel dipublish manual, gak
  pernah auto-post), auto re-trigger measurement loop (jadwal otomatis
  cek ulang performa 28 hari setelah sebuah rekomendasi di-Apply).
- **Fase D — Perlu keputusan lanjutan sebelum dikerjakan:** **Backlink
  Monitor** (read-only, lewat GSC Links report yang udah gratis diakses
  — sengaja gak ada outreach otomatis), riset keyword pakai API
  berbayar (trade-off biaya vs akurasi, belum diputuskan).

Prinsip yang tetap dijaga di semua fase: AI cuma menyarankan, gak ada
publish/posting/outreach otomatis — sama persis kayak guardrail Growth
Agent v1 yang sudah berjalan.

**Aturan arsitektur wajib (ditetapkan 2 Agu 2026):** semua agent — lama
maupun baru — cuma boleh menulis usulan ke Action Queue
(`growth_agent_jobs`), dibedakan lewat `job_type`/`agent_key`. Dilarang
bikin tabel antrian sendiri per-agent, dilarang ada aksi yang jalan tanpa
row di antrian + approval manusia, dan hasil setelah di-approve dicatat
balik ke baris yang sama (`output_json` + `growth_agent_feedback`).
Detail lengkap + alasannya di `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 1b —
**wajib dibaca sebelum mengerjakan agent baru mana pun.**

## Later / Backlog

Diketahui perlu dikerjakan suatu saat, tapi bukan prioritas sekarang.

- ⏸️ **On Hold** — Fase 7: App Promotion module (badge Android/iOS di
  homepage). Saat ini baru placeholder statis (logo + teks "Segera Hadir").
  Belum dikerjakan atas permintaan eksplisit user — tunggu keputusan lanjut
  kapan modul ini (app beneran) mulai dibangun.

---

**31 Jul 2026:**
- **Backup otomatis mingguan (DB + file) ke Google Drive** disetup penuh
  di server (bukan di repo/sandbox — operasi cPanel murni). rclone
  terpasang manual (binary standalone, tanpa root) dan terhubung ke
  Google Drive lewat OAuth scope `drive.file` (least privilege). Script
  `~/backup-weekly.sh` di server men-dump `sagagoal_cms` + tar seluruh
  `public_html`, upload kedua file ke folder Drive `SagagoalBackups`,
  lalu bersihkan backup lokal >14 hari. Dijadwalkan lewat Cron Job cPanel
  (`0 2 * * 0`, tiap Minggu 02:00). Diverifikasi end-to-end: file muncul
  benar di Google Drive, isi dump lengkap (31 tabel). Detail teknis penuh
  (termasuk 2 bug nyata yang ketemu & diperbaiki saat setup — password DB
  mengandung `#` kepotong sebagai komentar di file `.my.cnf`, dan
  peringatan non-fatal `PROCESS privilege` dari `mysqldump` di
  shared-hosting) ada di `docs/BACKUP_WORKFLOW.md`. Restore belum pernah
  dites sungguhan — dicatat eksplisit sebagai gap di dokumen itu.

**4 Agu 2026:**
- **Fix layout halaman listing berita + kolom iklan sidebar kanan** (commit
  `b454965`) — bug nyata: `kategori.php` memotong grid 3-kolom di TENGAH
  baris (`$i === 4`) untuk menyisipkan iklan, menyisakan sel kosong yang
  terlihat di production; pemotongan itu bahkan terjadi saat slot iklannya
  kosong, jadi layout rusak tanpa dapat apa-apa. Diganti: iklan jadi grid
  item biasa dengan `grid-column: 1 / -1` (selalu jadi baris utuh di jumlah
  kolom berapa pun, jadi mustahil menyisakan lubang di breakpoint mana pun).
  Plus layout `.news-grid-layout` untuk sidebar kanan, mengikuti pola
  `.article-layout` yang sudah ada (aside tidak dirender sama sekali kalau
  slot kosong, bukan disembunyikan CSS). Ditemukan 1 bug specificity saat
  review: override 2-kolom `.news-grid-layout--right-only .crypto-grid--3`
  (0,2,0) mengalahkan rule mobile `.crypto-grid--3` (0,1,0) di `@media
  max-width 640px` — media query tidak menambah specificity — sehingga kartu
  tidak collapse ke 1 kolom di HP saat iklan sidebar aktif; diperbaiki
  dengan rule tandingan di dalam media query yang sama.
- **Structured data NewsArticle + BreadcrumbList di halaman artikel**
  (commit `e238083`) — GSC melaporkan "URL has no enhancements"; sebelumnya
  `artikel.php` cuma punya schema FAQPage (itu pun hanya bila artikel punya
  FAQ). Ditambah NewsArticle (headline dipotong 110 char pakai `mb_substr`,
  field `image` dihilangkan sepenuhnya bila tidak ada gambar) dan
  BreadcrumbList yang mencerminkan breadcrumb visual persis termasuk kasus
  artikel tanpa kategori. `og:type` di `includes/site-header.php` yang dulu
  hardcode `"website"` sekarang bisa di-override lewat `$ogType` opsional
  (default tetap `website`, jadi halaman lain tidak berubah). Sekalian
  `index.php` canonical diselaraskan dari `.../index.php` ke `.../` —
  ternyata selama ini bertentangan dengan sitemap, yang di
  `cms_sitemap_path_for()` sudah lama memakai `'homepage' => ''`.
- **Perbaikan XSS pada semua JSON yang dicetak ke dalam `<script>`** (bagian
  dari commit `e238083`) — ditemukan saat review structured data: blok
  JSON-LD memakai `JSON_UNESCAPED_SLASHES` tanpa `JSON_HEX_TAG`, sehingga
  judul artikel berisi `</script>` bisa menutup blok lebih awal dan
  dieksekusi sebagai JavaScript. Yang lebih serius, grep menemukan pola sama
  di `cms-admin/pages/ads.php` (`articleTitleToId`/`categoryNameToId`,
  judul artikel jadi key JS object, tanpa flag apa pun) — ini jalur
  **eskalasi hak akses**: role `editor` (tier terendah RBAC, lihat
  `docs/DECISIONS.md` 2026-07-15) bisa menanam payload lewat judul artikel
  yang kemudian tereksekusi di browser superadmin saat membuka halaman Ads.
  `JSON_HEX_TAG` ditambahkan di semua titik: 3 blok JSON-LD di `artikel.php`
  (termasuk FAQPage lama), `ads.php` (3 titik), `banners.php`,
  `site-settings.php`, `pages.php`. Diverifikasi dengan payload nyata di
  browser sungguhan — tidak ada tag script yang tertutup prematur, dan
  picker artikel/kategori di halaman Ads dikonfirmasi masih berfungsi
  (lookup tetap cocok karena `\uXXXX` di-decode balik oleh parser JS).
- ✅ **Growth Agent v2 Fase A item 1 — scheduler mandiri SELESAI** (commit
  `1396389`). File baru `cron/growth_agent_maintenance.php`: thin CLI
  wrapper yang memanggil 5 fungsi maintenance yang sama persis dengan yang
  dipanggil `growth-agent.php` saat page load (`ensure_schema`,
  `cleanup_old_jobs` 90 hari, `gsc_fetch_if_stale` 24 jam,
  `detect_memory_if_stale`, `snapshot_performance_if_stale` 24 jam) —
  mengikuti pola 5 cron yang sudah ada di folder `cron/`. Tidak require
  `auth.php` (di CLI itu akan redirect+exit sebelum apa pun jalan). Tidak
  ada kill-switch level-script berbasis status GSC, karena `ensure_schema`
  dan `cleanup_old_jobs` tidak bergantung GSC sama sekali. Guardrail
  `GROWTH_AGENT_V2_PROPOSAL.md` § 1b diverifikasi baris-per-baris: nol job
  siap-eksekusi dibuat, nol panggilan AI, nol tulisan ke tabel `pages`.
  5 pemanggilan lazy di `growth-agent.php` **sengaja dipertahankan** sebagai
  safety net (pola sama dengan auto-migration PHP vs migrasi SQL, lihat
  `docs/DECISIONS.md` 2026-07-13). Terdaftar di cPanel Cron Jobs
  `0 4 * * *` (sengaja bukan jam 3, itu sudah dipakai `sync_f1_races.php`),
  log ke `~/logs/cron/growth_agent_maintenance.log`.
- ✅ **Growth Agent v2 Fase A item 3 — SEO-G0 Gate SELESAI.** Pre-check
  deterministik (tanpa AI) sebelum usulan artikel baru dibuat, bersifat
  **peringatan bukan blokir** atas keputusan eksplisit user. Tiga cek:
  usulan kembar yang masih pending, topik sudah dicakup artikel published,
  dan topik yang sudah tercatat di content-conflict/cannibalization.
  Dipanggil dari `cms_growth_agent_generate_article_idea()` (sebelum
  panggilan AI) dan `cms_growth_agent_request_topic_gap_article()`. Nol
  tabel/kolom baru — hasil gate di `growth_agent_jobs.input_brief`, ambang
  di `gsc_settings.opportunity_thresholds_json`. UI: badge ⚠ SEO-G0 di
  panel "Job Terbaru" yang hanya muncul saat ada peringatan; tidak ada
  menu/halaman/modul baru. Keterbatasan yang diketahui: ambang kemiripan
  belum tervalidasi di volume data asli (DB lokal cuma 2 artikel published,
  production 24) — perlu evaluasi & tuning setelah dipakai. Detail lengkap
  di `docs/GROWTH_AGENT_V2_PROPOSAL.md` § Fase A.
- ✅ **Growth Agent v2 Fase B item 1 — Internal Linking Agent SELESAI.**
  Job type `internal_link_suggestion` di `growth_agent_jobs` (nol tabel
  baru), deteksi deterministik tanpa AI dengan memakai ulang tokenizer
  SEO-G0. Tombol "Scan Internal Linking" di `growth-agent.php`, halaman
  review baru `cms-admin/pages/internal-link-review.php`. Penyisipan link
  lewat DOMDocument+XPath (atribut/`<a>`/`<script>` mustahil tersentuh),
  UTF-8 aman, hanya kemunculan pertama, dengan verifikasi ulang HTML hasil
  — kalau tidak aman, operasi dibatalkan total. Apply menulis ke
  `pages.content` TAPI menyimpan snapshot isi lama ke `output_json` dulu
  (CMS ini tidak punya sistem revisi, jadi itu satu-satunya jalan pulang),
  seluruhnya dalam satu transaksi ber-rollback, plus guard anti-timpa kalau
  artikel sudah diedit sejak usulan dibuat. Detail lengkap di
  `docs/GROWTH_AGENT_V2_PROPOSAL.md` § Fase B.
- **Perbaikan kualitas anchor Internal Linking Agent** — usulan pertama di
  data production menghasilkan anchor "paling" (kata generik) yang sempat
  diterapkan ke artikel live. Diperbaiki secara struktural, bukan dengan
  menambah stopword: anchor satu-kata sekarang wajib lolos DUA sinyal —
  document frequency korpus (≤20% artikel, self-adjusting) DAN kapitalisasi
  tengah-kalimat di body artikel sumber. Terbukti menahan kasus kedua
  ("Akhir", df 21%) yang tidak pernah didaftarkan manual. Sekalian
  memperbaiki 2 bug yang ditemukan devs saat pengujian: tanda baca
  menggantung di ujung anchor, dan urutan "terpanjang duluan" yang salah
  setelah frasa dipangkas. Detail di `docs/GROWTH_AGENT_V2_PROPOSAL.md`
  § Fase B.
- **DB production disalin ke lokal untuk pengujian** — dua masalah kualitas
  berturut-turut lolos karena DB lokal cuma punya 2 artikel published
  sementara production punya 24. Dump di-import ke lokal dengan SELURUH
  kredensial dikosongkan lebih dulu: AI (`ai_credentials`), GSC
  (`gsc_settings`), dan API-Football (`sports_api_settings` — kategori ini
  tidak ada di instruksi awal, ditemukan & dikonfirmasi devs sendiri;
  tanpa itu cron sync lokal bisa menembak API production). Password admin
  lokal di-set ulang, bukan dari hash production.
- **Backup otomatis dipindah ke akun Google Drive baru** — remote rclone
  lama `gdrive` dihapus & dibuat ulang dengan nama `WPM-sagagoal` (ketemu
  bug: "Edit existing remote" + jawab "No" di prompt refresh token TIDAK
  memicu browser-auth baru, rclone diam-diam menyimpan ulang token lama;
  harus delete + create baru). 2 backup lama sengaja ditinggal di akun
  lama. Detail di `docs/BACKUP_WORKFLOW.md`.

## Done (arsip ringkas)

Ringkasan per fase/tanggal — diambil dari `SITEMAP.md` § Update Log dan
`HANDOFF.md` § "Yang sudah dikerjakan". **Untuk detail teknis lengkap per
perubahan, selalu rujuk `SITEMAP.md`** — daftar di bawah ini sengaja
diringkas, bukan pengganti.

**Fase besar (kronologis, tanpa tanggal presisi):**
- Fase 1 — Hapus modul Products & Gallery dari admin/frontend (data DB
  dipertahankan saat itu; drop resmi baru disiapkan belakangan di migrasi
  008, masih opt-in/pending — lihat "Now").
- Fase 2 — Modul Articles: kategori, tag, author, SEO fields,
  featured/trending flag, view counter, preview page.
- Fase 3 — Modul Advertisements: CRUD iklan, posisi, settings, statistik,
  endpoint tracking publik (kemudian dikembangkan lebih jauh, lihat 14 Jul
  2026 di bawah).
- Fase 4 — Featured/Pamungkas: homepage section builder dinamis.
- Fase 5 — Integrasi Crypto API (provider-agnostic, default CoinGecko,
  cache + fallback + error log).
- Fase 6 — Integrasi Livescore API (kemudian dihapus total — lihat 15 Jul
  2026 di bawah).
- Fase 8 — Restructure sidebar admin & dashboard widgets.
- Fase 9 — Frontend jadi multi-halaman (homepage/artikel/kategori/
  crypto/livescore/pencarian), seluruh 11 ad slot mulai benar-benar
  dirender.
- Fase 10 (13 Jul 2026) — Migrasi SQL formal dibuat
  (`cms-admin/migrations/000`–`007`), diverifikasi silang column-by-column
  ke dump database live (`wpm_cms`, 38 tabel).
- Fase 11 (13 Jul 2026) — Full verification pass (`LAPORAN-AKHIR.md`),
  cleanup file-file mati sisa pre-pivot (`sample-data.php`,
  `migrate-media-library.php`, `migrate-ai-management.php`, dll).

**13 Jul 2026:**
- Clean URL diimplementasikan via `.htaccess` + helper `wpm_url_*()` (URL
  `.php?param=` lama tetap jalan, tidak ada link putus).
- "Landing Page" lama (peninggalan pre-pivot TheAwsoft) direpurpose jadi
  halaman admin "About" untuk kelola section Tentang Kami.
- Modul Banners & Special Pages ditemukan orphan (belum konek ke frontend
  sama sekali) — disambungkan ke frontend.
- Live Ticker: awalnya WebSocket Binance langsung (client-side), diganti
  jadi polling server-side — domain Binance dikonfirmasi diblokir ISP
  Indonesia.
- Migrasi `008_remove_products.sql` disiapkan (destructive, opt-in).

**14 Jul 2026:**
- Special Pages ditarik balik total dari admin panel (dianggap belum
  benar-benar dipakai) — termasuk bagian frontend yang baru disambungkan
  sehari sebelumnya. Tabel DB sengaja dipertahankan (belum di-drop).
- Checkbox UI dirapikan jadi satu komponen global (`.field--checkbox`),
  menghapus banyak duplikasi `<style>` lokal per halaman.
- Advertisements dikembangkan dari image-banner-only jadi 5 format iklan
  (Text/Image/Video/Custom HTML/External Ad Code) + bugfix sidebar ad
  duplikat dan device targeting yang tidak pernah berfungsi.
- Modul Sitemaps baru: `sitemap_urls`/`sitemap_changelog`/`sitemap_settings`,
  hook otomatis dari Articles/Categories/Tags/Redirects, endpoint publik
  `/sitemap.xml` + 4 sub-sitemap.
- Audit database tabel/kolom tak terpakai → migrasi
  `012_cleanup_unused_tables_columns.sql` disiapkan (destructive, opt-in).
- Live Ticker diubah dari statis jadi scrolling ticker; bar chart "Top 10
  Market Cap" ditambahkan di halaman Crypto.
- Site Settings disambungkan penuh ke frontend (nama situs, tagline, logo,
  kontak — sebelumnya semua hardcode).
- Bugfix layout tabel "All Sitemap URLs" dan grid kartu SEO Dashboard.

**15 Jul 2026:**
- Fix HTTP 404 pada tombol Generate SEO/Article/FAQ — folder
  `cms-admin/api/` (belum pernah ada) dibuat, plus helper
  `cms_ai_resolve_agent()`/`cms_ai_extract_json()` baru di `ai-helpers.php`.
- Fix preview logo/gambar tidak muncul di admin online — akar masalah:
  JS di beberapa halaman pakai `BASE_URL` mentah, bukan
  `cms_public_base_prefix()` (lihat `docs/DECISIONS.md`).
- **Modul Livescore Sepak Bola dihapus total** (admin + frontend + DB) atas
  permintaan eksplisit user — akan dibangun ulang sebagai project terpisah.
  Drop tabel resmi disiapkan di migrasi `013_remove_livescore_module.sql`
  (destructive, opt-in — lihat "Now").
- **Role-Based Access Control (RBAC)** 3 tier (Editor/Admin/Super Admin)
  diimplementasikan penuh (`cms_require_role()` dkk), sekaligus memperbaiki
  bug lama: value role yang tersimpan di DB salah format (Title Case+spasi
  alih-alih lowercase-no-spasi — lihat `docs/DECISIONS.md`).

**27 Jul 2026:**
- **Growth Agent + integrasi Google Search Console** di-*port* dari project
  sibling `wpm.sagacrypto.com` (logic generik, bukan crypto-specific).
  Termasuk: tabel `gsc_settings`/`gsc_query_data`/`gsc_opportunities` (GSC
  Collector via service-account JWT, credential terenkripsi sama seperti AI
  Credentials), Opportunity Engine deterministik berbasis threshold config,
  3 job type Growth Agent (`seo_recommendation`, `gsc_content_optimization`,
  `gsc_article_idea`) di atas infra AI existing (`ai-helpers.php`), dan
  Action Queue `growth_agent_jobs` + `growth_agent_feedback` (approve/
  reject/closed_as_legacy) lengkap dengan notifikasi bell dan cleanup job
  lama. Alur "Apply SEO Recommendation" sudah nulis balik
  `meta_title`/`meta_description` ke tabel `pages`. Detail gap yang masih
  perlu dikerjakan (dibandingkan `docs/GROWTH_AGENT_SEO_ROADMAP.md`) ada di
  section "Next" di atas.
- **Content Agent Adapter untuk `gsc_article_idea`** (gap #1, MVP item #5
  `GROWTH_AGENT_SEO_ROADMAP.md`) — approve pada job `gsc_article_idea`
  sekarang otomatis membuat draft artikel beneran di tabel `pages`
  (`status='draft'`), bukan cuma menandai job `succeeded` seperti
  sebelumnya. Title dari output AI jadi judul artikel, slug di-generate +
  di-dedupe otomatis (`cms_slugify()` + suffix `-2`/`-3` bila bentrok),
  outline dirapikan jadi placeholder `<h2>`/`<p>` per section (bukan full
  artikel — full-article generation tetap manual lewat Content Agent
  existing `article-generate.php`). `growth_agent_jobs.page_id` di-set ke
  draft yang baru dibuat, dan Recent Jobs menampilkan link "Edit draft"
  begitu itu terisi. Guardrail roadmap tetap dijaga: approve tidak pernah
  auto-publish (selalu `draft`), dan kalau insert draft gagal, job tetap
  di-log sebagai `failed` dengan `error_message` asli — tidak ada
  growth_agent_feedback row yang ditulis di jalur gagal itu, jadi job
  tidak terlihat seolah sudah di-approve padahal drafnya tidak pernah
  jadi. Fungsi baru: `cms_growth_agent_create_article_draft_from_idea()`
  di `growth-agent-service.php`.

**28 Jul 2026:**
- **Indexing Workflow** (gap #2, Phase 5 `GROWTH_AGENT_SEO_ROADMAP.md`) —
  baca status index artikel published lewat Search Console URL Inspection
  API (`urlInspection.index:inspect`), reuse credential/token flow yang
  sama dengan GSC Collector (`cms_gsc_get_access_token()`, scope
  `webmasters.readonly`) — tidak ada credential atau scope baru. Tabel baru
  `gsc_url_inspections` (upsert per `page_id`, satu row per artikel,
  simpan verdict/coverage_state/robots_txt_state/indexing_state/
  page_fetch_state/last_crawl_time/canonical fields/sitemap +
  `raw_response_json` mentah). Trigger manual saja (tidak ada cron di
  codebase ini): tombol "Inspect URL" per artikel dan "Inspect prioritas"
  (batch, default 10, kombinasi artikel yang terkait
  `gsc_opportunities` open+high-priority dan artikel yang belum
  pernah/lama diinspeksi) di panel baru "Index Status" pada
  growth-agent.php. Saat verdict bukan `PASS` (atau coverage_state
  menunjukkan duplicate/redirect/not indexed), job baru
  `review_indexing_issue` otomatis dibuat (`status='manual_action'`,
  `agent_key='gsc_indexing'`, dedup per page_id selama masih unresolved)
  — checklist penyebabnya (`cms_growth_agent_build_indexing_checklist()`)
  murni deterministik (pattern-matching terhadap enum verdict dari
  Google), BUKAN dari AI, dan isinya cuma checklist + data verdict mentah,
  bukan rekomendasi tulis ulang artikel. Halaman baru
  `indexing-issue-review.php` (read-only, mirip
  `seo-recommendation-review.php` tapi tanpa Apply — dua aksi: "Tandai
  Sudah Ditinjau" dan "Tutup sebagai Legacy") menampilkan checklist +
  verdict lengkap, link "Review" muncul di Recent Jobs begitu ada job
  `manual_action`. Guardrail roadmap dijaga penuh: **tidak pernah** pakai
  Google Indexing API (`indexing.googleapis.com` — itu khusus
  JobPosting/livestream, bukan artikel biasa) di mana pun dalam
  implementasi ini, dan index issue **tidak pernah** otomatis memicu
  tulis ulang/republish artikel — keputusan perbaikan sepenuhnya manual.
  Ditest end-to-end dengan URL Inspection API asli (bukan mock): inspect
  artikel published sungguhan, verifikasi row `gsc_url_inspections`
  terbentuk benar (termasuk lewat 1 bug nyata yang ketemu & langsung
  diperbaiki saat testing — parameter `page_id` ekstra yang tidak dipakai
  di query UPDATE, ditolak PDO karena `ATTR_EMULATE_PREPARES` di-nonaktifkan
  di `config/database.php`), verdict `NEUTRAL` sungguhan berhasil memicu
  job `review_indexing_issue` dengan checklist yang benar, dedup jalan
  (tidak duplikat job selama belum resolved), dan kedua aksi di halaman
  review (Mark Reviewed / Close as Legacy) diverifikasi sampai ke
  `growth_agent_jobs.status` & `growth_agent_feedback.action`.
- **Agent Memory** (gap #3, `GROWTH_AGENT_SEO_ROADMAP.md` § Growth memory)
  — melengkapi porting yang sebelumnya sengaja dilewati: tabel baru
  `growth_agent_memory` (dedupe via `dedupe_key` hash, sama konvensi
  dengan `gsc_opportunities` — bukan UNIQUE key langsung di kolom nullable
  `matched_page_id`/`query_text`, karena MySQL tidak menjamin uniqueness
  saat salah satu kolom NULL). Deteksi deterministik (bukan AI) di
  `cms_growth_agent_detect_memory_patterns()`, pakai
  `cms_gsc_get_memory_thresholds()` yang sudah lama ada tapi belum pernah
  dipanggil siapa pun: **winning_pattern** (scope page atau query — >=
  `min_distinct_weeks` minggu berbeda, avg CTR & posisi & total
  impressions lolos threshold) dan **content_gap** (scope query — query
  recurring persisten lintas minggu yang belum pernah py matched_page_id,
  beda dari `gsc_opportunities`' kategori "No article" yang cuma reaksi
  window fetch saat ini). Promosi status `pending_review` → `active`
  butuh dua kali terdeteksi konsisten; `stale` yang terdeteksi ulang balik
  ke `pending_review` dulu (tidak langsung `active`); housekeeping
  otomatis men-stale-kan row yang tidak dikonfirmasi ulang dalam
  `active_stale_days`/`pending_review_stale_days`. Trigger lazy dari
  page-load `growth-agent.php` (`cms_growth_agent_detect_memory_if_stale()`,
  pola sama dengan `cms_gsc_fetch_if_stale()`), bukan cron. **Guardrail
  advisory-only dijaga penuh**: `GrowthAgentPromptBuilder::buildMemoryContext()`
  (dipanggil dari dalam `buildContext()`, otomatis menjangkau
  article_draft/seo_recommendation/gsc_content_optimization/gsc_article_idea
  sekaligus tanpa perlu ubah 4 tempat terpisah) cuma membaca row
  `status='active'` dan menambah teks ke prompt — tidak ada satu pun jalur
  kode yang membuat/approve/execute `growth_agent_jobs` dari memory. UI
  panel baru "Agent Memory" di growth-agent.php: read-only (tipe, target,
  status, evidence, minggu terdeteksi, terakhir dikonfirmasi), satu-satunya
  aksi manual "Tandai stale" (`cms_growth_agent_mark_memory_stale()`) —
  sengaja bukan approve/execute karena memory bukan action queue. Ditest
  end-to-end dengan data GSC asli (`gsc_query_data` kosong saat ini, jadi
  data 4-minggu di-seed manual lalu dihapus lagi setelah verifikasi, sesuai
  instruksi task — tidak ada data test yang ditinggal): promosi
  `pending_review`→`active` lintas 2 run deteksi, idempotency di run ke-3,
  `stale`→`pending_review` saat redetect, housekeeping men-stale-kan row
  `active`/`pending_review` yang lewat window (row sintetis backdated,
  terpisah dari data seed utama), `buildMemoryContext()`/`buildContext()`
  menghasilkan teks yang benar, dan aksi "Tandai stale" di UI diverifikasi
  sampai ke `growth_agent_memory.status`.
- **Feedback Loop / Before-After** (gap #4, `GROWTH_AGENT_SEO_ROADMAP.md` §
  Phase 6) — melengkapi tabel `growth_agent_performance` yang sebelumnya
  schema-only. Kolom baru `impressions` ditambahkan (skema lama cuma
  `pageviews`/`clicks`/`avg_ranking_position`/`ctr`, tidak cukup untuk
  hitung CTR & weighted-average position dengan benar). Snapshot harian
  lazy `cms_growth_agent_snapshot_performance()` — agregasi per
  (page_id, metric_date) dari `gsc_query_data`, `avg_ranking_position`
  di-weight by impressions (bukan AVG polos, supaya query ber-impression
  kecil tidak menggeser posisi halaman yang sebenarnya), upsert via
  `ON DUPLICATE KEY UPDATE` pada `UNIQUE(page_id, metric_date)` yang sudah
  ada. Trigger lazy dari page-load `growth-agent.php`
  (`cms_growth_agent_snapshot_performance_if_stale()`, default 24 jam,
  gsc_settings kolom baru `last_performance_snapshot_at`) — pola sama
  dengan `cms_gsc_fetch_if_stale()`/`cms_growth_agent_detect_memory_if_stale()`,
  bukan cron. `cms_growth_agent_compare_before_after()` membandingkan
  window N-hari (default 28) sebelum vs sesudah satu `change_date`, pakai
  `growth_agent_performance` sebagai sumber utama (durable, tidak pernah
  di-prune) dengan fallback ke `gsc_query_data` langsung kalau
  cakupan-harinya lebih lengkap di sana (mis. snapshot belum sempat
  jalan) — dan **wajib** mengembalikan `insufficient_data` (tanpa
  menghitung delta sama sekali) kalau salah satu sisi kurang dari 7 hari
  data, persis guardrail roadmap ("jangan dipaksakan jadi kesimpulan").
  Sumber "artikel yang pernah kena action": `cms_growth_agent_get_feedback_report()`
  cuma mengambil `seo_recommendation` yang **beneran** sudah di-Apply
  (`status='succeeded'`, `change_date` = `job.updated_at` — satu-satunya
  momen job ini berstatus succeeded adalah lewat Apply di
  `seo-recommendation-review.php`) dan `gsc_article_idea` yang draft-nya
  **beneran** sudah dipublish (join ke `pages.status='published'`,
  `change_date` = `pages.published_at`). **`gsc_content_optimization`
  sengaja dikeluarkan** — ditelusuri ke kodenya langsung dan dikonfirmasi
  ke user dulu sebelum coding: job type ini tidak pernah punya event
  "diterapkan ke artikel" yang bisa dipercaya (statusnya `succeeded`
  begitu AI selesai generate, bukan begitu manusia benar-benar
  menerapkan saran ke artikel — beda dari `seo_recommendation` yang punya
  Apply asli), jadi memasukkannya akan berarti mengukur before/after di
  sekitar tanggal yang mungkin tidak ada hubungannya dengan perubahan
  nyata apa pun. UI panel baru "Feedback / Before-After" — murni laporan,
  tidak ada approve/execute, baris dengan data tipis ditandai badge "Data
  belum cukup". Ditest end-to-end dengan skenario nyata: job
  `seo_recommendation` sungguhan dari sebelumnya (page 90004) dipakai
  ulang, plus 2 skenario tambahan di-seed manual (satu artikel
  `gsc_article_idea` yang dipublish, satu kasus data tipis yang sengaja
  cuma diisi beberapa hari "sesudah") — hasil hitung delta clicks/
  impressions/ctr/avg_position diverifikasi benar secara matematis persis
  terhadap data yang di-seed, kasus tipis benar-benar menghasilkan
  `insufficient_data` tanpa delta, tampilan panel UI dicek sesuai, lalu
  seluruh data seed (job, page sementara, baris `gsc_query_data`/
  `growth_agent_performance`) dihapus lagi setelah verifikasi.
- **Cannibalization + Content Decay detection** (gap #5, Phase 2
  `GROWTH_AGENT_SEO_ROADMAP.md`) — 2 kategori opportunity terakhir yang
  belum ada, ditambahkan ke `cms_gsc_compute_opportunities()` yang sudah
  jalan (murni deterministik, tidak ada AI di deteksinya). Threshold baru
  di `opportunity_thresholds_json` (dikonfirmasi ke user dulu sebelum
  coding, karena tidak ada angka yang "pasti benar"): `decay_min_pct_decline`
  30% (turun clicks current-vs-previous window), `cannibalization_min_share`
  20% (tiap page yang bentrok harus pegang porsi berarti dari query itu) —
  keduanya bisa di-tuning lewat config tanpa ubah kode. **Content Decay**
  (scope page) pakai period-over-period compare baru
  (`comparison_window_days`, default 28 hari) di atas `gsc_query_data`,
  di-fold ke per-page loop yang sudah ada (bukan pass upsert terpisah) biar
  satu page bisa punya multi-category tanpa saling menimpa; halaman/query
  yang cakupan datanya kurang dari `comparison_min_days` (7 hari) di salah
  satu sisi **dilewati diam-diam** (bukan opportunity palsu) — beda dari
  Feedback Loop yang punya badge `insufficient_data` eksplisit, karena
  `gsc_opportunities` memang cuma daftar peluang nyata, bukan laporan
  audit tiap page. Dirutekan ke `recommended_action='gsc_content_optimization'`
  (ENUM existing, tidak ada action type baru) tapi
  `cms_growth_agent_generate_content_optimization()` sekarang menerima
  context `is_decay` + evidence tren dan switch ke system prompt yang beda
  ("artikel ini declining, cek yang basi" — bukan "belum tembus page one,
  tambah kedalaman"). **Cannibalization** (scope query, 2+ matched
  page published) murni snapshot window tunggal (bukan period-over-period
  — beda dari Content Decay, karena ini pertanyaan "sekarang" bukan
  "tren"), butuh ALTER ENUM widen-safe (pola sama
  `cms_growth_agent_ensure_legacy_status()`) nambah
  `gsc_opportunities.recommended_action = 'cannibalization_review'`.
  **Sengaja TIDAK ada AI/Generate untuk cannibalization** — cuma
  `cms_growth_agent_log_cannibalization_review()` (job baru
  `agent_key='manual_review'`, murni surface data) dan halaman baru
  `cannibalization-review.php` (read-only, mirip
  `indexing-issue-review.php`, tanpa Apply/generate — cuma "Tandai Sudah
  Ditinjau"/"Tutup sebagai Legacy") karena keputusan pisah intent/
  konsolidasi/pilih pillar page wajib judgment manusia. UI: kedua kategori
  otomatis muncul di panel "Prioritized Opportunities" yang sudah ada —
  tombol "Generate" untuk Content Decay, tombol "Review" (bukan Generate)
  untuk Cannibalization. Ditest end-to-end dengan data di-seed manual (2
  artikel published asli): decay case (40% penurunan clicks) berhasil
  terdeteksi dengan evidence matematis benar (`prev_clicks`/`cur_clicks`/
  `pct_change_clicks` di `metrics_json` persis sesuai data seed), kasus
  data-kurang berhasil dilewati (tidak ada opportunity palsu), cannibalization
  50/50 share antara 2 artikel terdeteksi + direview penuh lewat UI sampai
  `growth_agent_jobs.status`/`growth_agent_feedback.action`, dan dispatch
  "Generate" ke prompt khusus decay diverifikasi sampai tahap pemanggilan
  AI provider sungguhan (gagal di step kredensial test environment, bukan
  di logic aplikasi — lihat catatan di transcript kerja). Seluruh data seed
  (termasuk 1 baris `ai_agent_settings` sementara buat test) dihapus lagi
  setelah verifikasi. **Ini menutup seluruh gap yang tercatat di roadmap
  ini** — lihat catatan penutup di section "Next" di atas.

---

## Aturan pakai dokumen ini

- **Setiap kali ada perubahan prioritas** (fitur baru mulai dikerjakan,
  urutan antrian berubah, sesuatu di-hold/di-unhold) → update section
  **Now** / **Next** / **Later** di atas saat itu juga, jangan ditunda.
- **Saat sebuah item selesai** → pindahkan ke section **Done** (ringkas,
  ikut format tanggal + satu-dua baris seperti di atas), **jangan dihapus**
  dari dokumen ini sama sekali. Detail teknis lengkapnya tetap dicatat di
  `SITEMAP.md` § Update Log seperti biasa — bagian Done di sini cuma index
  ringkas yang nunjuk ke sana.
- Kalau sebuah item destructive/butuh eksekusi manual (migrasi SQL, dll),
  selalu tandai 🔴 Blocked di "Now" sampai user konfirmasi sudah dijalankan
  — jangan pindahkan ke Done duluan berdasarkan asumsi.
