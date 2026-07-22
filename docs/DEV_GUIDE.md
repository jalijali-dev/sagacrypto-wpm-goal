# SagaCrypto / WPM — Dev Guide

> Konvensi teknis wajib + checklist siap pakai untuk siapa pun (manusia atau
> Claude) yang mengerjakan project ini. Diperluas dari ringkasan yang ada di
> `HANDOFF.md` § 2 — dokumen ini sekarang jadi rujukan paling lengkap untuk
> konvensi teknis (versi ringkas di `HANDOFF.md` § 2 tetap dibiarkan apa
> adanya di sana sebagai quick-glance, tidak dihapus). Untuk konteks project
> & fase kerja, lihat `HANDOFF.md`. Untuk peta route/menu, lihat
> `SITEMAP.md`. Untuk alasan di balik keputusan-keputusan di bawah, lihat
> `docs/DECISIONS.md`.

---

## 1. Checklist konvensi wajib (cek sebelum commit/deliver perubahan apa pun)

- [ ] **`declare(strict_types=1);` adalah statement PERTAMA** di setiap file
      PHP, persis setelah `<?php`. Tidak ada pengecualian, tidak ada file
      "terlalu kecil untuk butuh ini".
- [ ] **PDO selalu dibuat dengan `PDO::ATTR_EMULATE_PREPARES => false`**
      (lihat `cms-admin/config/database.php` — file ini di-`.gitignore`,
      berisi kredensial lokal, jangan pernah commit versi dengan kredensial
      asli).
- [ ] **Semua query pakai prepared statement** (`$pdo->prepare()` +
      `->execute([...])`), tidak ada concatenation nilai dari
      `$_GET`/`$_POST`/`$_REQUEST` langsung ke dalam string SQL. Nilai
      numerik yang terpaksa masuk lewat `LIMIT`/`INTERVAL` (yang tidak bisa
      di-bind sebagai parameter di semua driver) wajib di-cast `(int)` +
      di-clamp dengan `min()`/`max()` dulu, tidak pernah dipakai mentah.
- [ ] **URL absolut dari admin balik ke frontend** → wajib pakai
      `cms_public_base_prefix()` (`cms-admin/includes/functions.php`), baik
      di sisi PHP maupun saat di-`json_encode()` untuk dipakai inline
      `<script>`. **Jangan pernah** pakai `BASE_URL` mentah untuk ini — lihat
      `docs/DECISIONS.md` untuk kenapa (bug berulang, sudah kejadian 2x).
- [ ] **Link relatif internal admin panel** → pakai `cms_action_href()` /
      `cms_api_href()` / `cms_nav_href()` (mendeteksi topologi nested/flat
      otomatis lewat `cms_is_pages_subdirectory()`), bukan hardcode path
      seperti `'../../api/...'`.
- [ ] **Halaman/action yang menyentuh role atau data sensitif** → pasang
      `cms_require_role([...])` di baris paling atas (setelah
      `require_once auth.php` + `config/database.php`), sebelum logic apa
      pun jalan. Role tersimpan di `admins.role`
      (`enum('superadmin','admin','editor')`) — **selalu lowercase, tanpa
      spasi**. Kalau nambah UI baru yang mengirim/menyimpan value role,
      double-check value yang dikirim persis salah satu dari tiga itu, dan
      tambahkan validasi server-side di sisi penerima (jangan percaya value
      dari form/dropdown begitu saja — lihat `admins-store.php`/
      `admins-update.php` untuk contoh pola validasinya).
- [ ] **Semua output ke HTML** di-escape lewat `cms_esc()` (admin) /
      `wpm_esc()` (frontend) kecuali memang sengaja merender HTML tepercaya
      (mis. konten WYSIWYG artikel, atau Custom HTML ad yang sudah lewat
      `cms_sanitize_ad_html()`).
- [ ] **Migrasi SQL baru** → nomor urut yang benar (cek file terakhir di
      `cms-admin/migrations/`), idempotent (`CREATE TABLE IF NOT EXISTS`,
      `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`, atau guard `NOT EXISTS`
      setara) kalau non-destructive. Kalau destructive (DROP
      TABLE/COLUMN) → file terpisah, opt-in, header comment yang jelas
      menyebut ini destructive dan harus dibaca dulu sebelum dijalankan.
      Lihat § 2 di bawah untuk alur eksekusinya.
- [ ] **Tidak ada file migrasi lama yang diedit** — hanya boleh file baru.
      Update `cms-admin/migrations/README.md` (tabel index) kalau relevan.
- [ ] **`SITEMAP.md` Update Log itu append-only** — jangan pernah menulis
      ulang entri lama, cuma tambah entri baru di bawah + update bagian
      "current state" (Feature Matrix, Peta Menu, dll) kalau berubah. Setiap
      kerjaan yang mengubah struktur (hapus/tambah menu, tabel, route) wajib
      update `SITEMAP.md` sebelum dianggap selesai.
- [ ] **`docs/ROADMAP.md`** ikut diupdate kalau perubahan itu menggeser
      prioritas kerja (lihat § "Aturan pakai" di file itu sendiri).

---

## 2. Cara jalanin migrasi

- **Siapa yang eksekusi:** selalu **user**, manual, lewat phpMyAdmin atau
  `mysql` client. Sandbox kerja (Claude) **tidak punya akses DB live** —
  tidak pernah bisa dan tidak pernah boleh mencoba menjalankan migrasi
  sendiri, sekalipun secara teknis ada cara untuk mencobanya.
- **Auto-migration PHP** (`cms_ensure_table()`/`cms_ensure_column()` di
  `cms-admin/includes/schema-guard.php`) jalan otomatis, lazy, setiap admin
  membuka halaman yang butuh tabel/kolom tersebut — ini yang dipakai di
  produksi sehari-hari untuk skema baru yang non-destructive. Developer
  tidak perlu (dan tidak harus) menyuruh user menjalankan migrasi manual
  untuk kasus ini, cukup pastikan halaman terkait pernah dibuka sekali.
- **File `.sql` di `cms-admin/migrations/`** dipakai untuk 3 kebutuhan yang
  tidak bisa dipenuhi auto-migration PHP: fresh install database kosong,
  dokumentasi/audit trail, dan disaster recovery/staging setup. Non-
  destructive → aman dijalankan berkali-kali (idempotent), urutan numerik
  mulai dari `000`.
- **Migrasi destructive** (saat ini: `008`, `012`, `013` — lihat
  `docs/ROADMAP.md` § Now untuk status terkini) → **opt-in**, harus dibaca
  headernya dulu oleh user, dijalankan manual, satu kali, sengaja. Jangan
  pernah menyarankan atau membungkus migrasi destructive supaya
  "otomatis jalan" demi kenyamanan.
- Setelah user konfirmasi sebuah migrasi destructive sudah dijalankan →
  update `docs/ROADMAP.md` (pindahkan dari "Now" ke "Done") dan catat di
  `SITEMAP.md` Update Log kalau ada perubahan struktur yang mengikutinya.

---

## 3. Aturan: jangan sentuh modul Crypto kecuali diminta eksplisit

Modul Crypto (Crypto Dashboard, Coin Settings, Crypto API settings, Live
Ticker, widget harga di homepage/`crypto.php`, tabel `crypto_api_settings`/
`crypto_cache`/`crypto_coin_settings`) adalah **fitur inti yang aktif
dipakai** — beda karakternya dari modul-modul lain di project ini yang
sering jadi target cleanup (Products, Gallery, Special Pages, Livescore).

- Jangan hapus, refactor besar, atau ubah struktur data modul ini kecuali
  user **secara eksplisit** minta perubahan pada modul Crypto.
- Kalau sedang mengerjakan sesuatu yang lain dan perubahan itu **nyerempet**
  file/tabel Crypto (mis. shared helper yang dipakai bareng), berhenti dan
  konfirmasi ke user dulu sebelum lanjut — jangan asumsikan "cuma nyerempet
  dikit, aman".
- Kalau melakukan operasi lintas-modul (mis. hapus modul lain yang pernah
  digabung ke grup sidebar "Integrations" bareng Crypto), verifikasi lewat
  grep bahwa nol referensi Crypto ikut ter-touch — dan sebutkan eksplisit di
  laporan/commit message bahwa Crypto **tidak** tersentuh (contoh konkret:
  lihat entri "Modul Livescore Sepak Bola dihapus total" di `SITEMAP.md`,
  yang eksplisit mencatat "Crypto sama sekali tidak tersentuh" sebagai hasil
  verifikasi, bukan asumsi).

---

## 4. Aturan: grep full codebase dulu sebelum hapus apa pun yang namanya generik

Sebelum menghapus tabel, kolom, file, atau fungsi — **terutama yang namanya
generik** (`menus`, `services`, `gallery`, dll — nama yang gampang salah
disangka "jelas tidak dipakai" padahal ternyata beririsan dengan hal lain
yang tidak berhubungan) — lakukan grep penuh codebase dulu untuk memastikan
tidak ada dependency lain yang masih memakainya. Jangan berasumsi dari nama
kolom/file saja.

Contoh nyata dari project ini: saat audit tabel tak terpakai (14 Jul 2026),
grep untuk kata "services" awalnya berpotensi salah kaprah — hit yang
muncul cuma path folder `/services/PromptLoader.php`, bukan tabel `services`
yang sedang diaudit. Tanpa grep penuh, tabel yang benar-benar mati (`menus`,
`portfolio`, `services`, `gallery`, `special_pages`) tidak akan bisa
dipastikan aman untuk di-drop.

- Kalau setelah grep masih ragu (nama terlalu generik untuk yakin 100% dari
  grep saja), **tandai sebagai risiko ke user** alih-alih langsung
  menghapus.
- Untuk kolom/tabel: cek referensi di seluruh `.php` **di luar** folder
  `cms-admin/migrations/` (migrasi lama memang boleh menyebut skema lama —
  itu bukan "masih dipakai", itu riwayat).
- Hasil grep (dependency ditemukan / nol referensi) sebaiknya dicatat di
  `SITEMAP.md` Update Log sebagai bukti kerja, bukan cuma diklaim.

---

## 5. Delivery pattern

Kalau kerjaan sudah selesai dan siap diserahkan ke user untuk diupload ke
server (bukan lewat git push langsung — lihat `docs/ROADMAP.md`, belum ada
commit git sama sekali di project ini sejauh ini):

- Paket perubahan dibuat jadi `.zip`, struktur folder **mengikuti struktur
  repo apa adanya** — folder `cms-admin/` **tetap dengan prefix-nya, TIDAK
  di-flatten**. Di server, `cms-admin/` memang subfolder nyata di dalam
  `public_html/` (`public_html/cms-admin/`) yang jadi document root
  subdomain admin (`wpm.sagacrypto.com`) lewat konfigurasi Document Root
  di cPanel — bukan hasil pemindahan/rename fisik saat deploy. Lihat
  `docs/DEPLOY_WORKFLOW.md` untuk struktur server & alur deploy lengkap.
- Sertakan `BACA-DULU.txt` di root zip: petunjuk upload + checklist
  verifikasi manual, dalam Bahasa Indonesia, ditulis untuk seseorang yang
  tidak melihat proses kerjanya langsung.
- Jangan asumsikan urutan upload sembarang aman — kalau ada dependency antar
  file (mis. migrasi SQL harus jalan sebelum kode yang mengasumsikan kolom
  baru ada), sebutkan urutannya eksplisit di `BACA-DULU.txt`.

---

## 6. Batasan sandbox yang perlu diketahui

- **Tidak ada akses PHP/MySQL live.** Verifikasi syntax PHP dilakukan lewat
  pengecekan statis (brace/paren/tag balance), bukan `php -l` (sering tidak
  tersedia). Migrasi destructive selalu disiapkan sebagai file `.sql`
  opt-in, tidak pernah dieksekusi langsung dari sandbox.
- **Tidak ada akses git live** untuk push/commit atas nama user tanpa
  diminta eksplisit — lihat aturan umum soal actions yang butuh konfirmasi
  eksplisit sebelum dieksekusi (destructive/hard-to-reverse/visible-to-others).
