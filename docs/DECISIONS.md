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

**Alasan:** Topologi deploy adalah split-subdomain (`sagacrypto.com` untuk
frontend, `wpm.sagacrypto.com` untuk admin — dua host berbeda secara HTTP
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
