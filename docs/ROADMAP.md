# SagaCrypto / WPM — Roadmap

> Peta prioritas kerja project ini. Untuk peta route/menu lengkap dan riwayat
> perubahan detail per-tanggal, lihat `SITEMAP.md` (root). Untuk konteks
> cepat & konvensi teknis, lihat `HANDOFF.md` (root) dan `docs/DEV_GUIDE.md`.

Terakhir diperbarui: **18 Juli 2026**

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

- 🔴 **Blocked** — 3 migrasi SQL destructive belum dieksekusi ke database live:
  `cms-admin/migrations/008_remove_products.sql`,
  `012_cleanup_unused_tables_columns.sql`,
  `013_remove_livescore_module.sql`. Semuanya sudah disiapkan & idempotent-safe
  untuk dibaca ulang, tapi **harus dijalankan manual oleh user** (via
  phpMyAdmin/mysql client) — sandbox kerja ini tidak punya akses DB live.
  Lihat `docs/DEV_GUIDE.md` § Migrasi untuk detail siapa/kapan.
- 🔴 **Blocked** — Belum ada satu pun git commit/push untuk seluruh riwayat
  project ini. Semua kerjaan sejauh ini hidup sebagai file di working
  directory saja. Menunggu keputusan user kapan mulai commit (dan apakah
  perlu di-squash/diatur per-fase atau commit besar sekali jalan).

## Next

Antrian berikutnya setelah item "Now" selesai. *(Kosong saat dokumen ini
dibuat — belum ada item konkret yang dijadwalkan user. Isi bagian ini saat
ada prioritas baru yang disepakati, jangan biarkan kosong tanpa keterangan.)*

## Later / Backlog

Diketahui perlu dikerjakan suatu saat, tapi bukan prioritas sekarang.

- ⏸️ **On Hold** — Fase 7: App Promotion module (badge Android/iOS di
  homepage). Saat ini baru placeholder statis (logo + teks "Segera Hadir").
  Belum dikerjakan atas permintaan eksplisit user — tunggu keputusan lanjut
  kapan modul ini (app beneran) mulai dibangun.

---

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
