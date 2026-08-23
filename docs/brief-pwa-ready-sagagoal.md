# Brief: PWA-ready Sagagoal.com

## Konteks

Operator mau sagagoal.com bisa di-"Add to Home Screen" dan kerasa kayak app
(punya icon sendiri, buka tanpa address bar, bisa cache buat baca artikel
offline). Situs sekarang PHP klasik server-rendered (index.php, artikel.php,
football.php, basket.php, f1.php, dst) dengan 1 shared header template
(`includes/site-header.php`) yang dipakai semua halaman — ini PENAMBAHAN
fitur di atas struktur yang ada, BUKAN migrasi/rombak arsitektur. Belum ada
`manifest.json` atau service worker sama sekali di codebase saat ini.

## Yang perlu ditambah (file baru)

1. **`manifest.json`** (root, sejajar `index.php`)
   - `name`, `short_name`, `start_url` (`/`), `display: "standalone"`,
     `theme_color`, `background_color`, array `icons` (192x192 & 512x512
     minimum, idealnya juga 384x384 + maskable icon).
   - Icon file baru ditaruh di `assets/img/` (folder ini udah ada).

2. **`sw.js`** (service worker, root — HARUS di root biar scope-nya cover
   seluruh domain, bukan di dalam subfolder)
   - Cache-first atau stale-while-revalidate buat asset statis
     (`assets/css/*`, `assets/js/*`, `assets/img/*`).
   - Network-first buat halaman artikel (biar konten berita tetap fresh,
     cuma fallback ke cache kalau offline — JANGAN cache-first buat
     artikel, ini situs berita, konten harus selalu up to date pas online).
   - Cache versioning (`CACHE_NAME = 'sagagoal-v1'`) + logic hapus cache
     lama di event `activate`, biar update gak numpuk cache basi.

3. **Icon set** di `assets/img/` — beberapa ukuran PNG turunan dari logo
   yang udah ada (kalau logo source-nya vector/besar, tinggal di-resize).

## Yang perlu diubah (file existing)

1. **`includes/site-header.php`** — karena ini shared di semua halaman,
   cukup edit SATU file ini buat efek ke seluruh situs:
   - Tambah `<link rel="manifest" href="/manifest.json">` di `<head>`.
   - Tambah `<meta name="theme-color" content="...">`.
   - Tambah script kecil register service worker (biasanya taruh sebelum
     `</body>` via `includes/site-footer.php`, bukan di header, biar gak
     block render):
     ```js
     if ('serviceWorker' in navigator) {
       window.addEventListener('load', () => {
         navigator.serviceWorker.register('/sw.js');
       });
     }
     ```

2. **`includes/site-footer.php`** — taruh script register service worker
   di sini (lihat poin di atas).

3. **`.htaccess`** — cek header caching buat `manifest.json` dan `sw.js`:
   `sw.js` sebaiknya `Cache-Control: no-cache` (biar browser selalu cek
   versi terbaru service worker), beda dari asset statis lain yang boleh
   di-cache lama.

## Yang TIDAK perlu diubah

- Tidak ada perubahan ke `artikel.php`, `football.php`, `basket.php`,
  `f1.php`, `page.php`, dst — semua tetap render server-side seperti biasa.
- Tidak ada perubahan ke Growth Agent / CMS admin — PWA ini murni
  front-end publik.
- HTTPS: kemungkinan sudah aktif di cPanel (wajib buat service worker
  jalan) — devs tinggal konfirmasi, bukan setup baru.

## Testing sebelum deploy

1. Buka DevTools → Application tab → Manifest: pastikan semua field
   ke-parse tanpa error, icon ke-load.
2. Application tab → Service Workers: pastikan `sw.js` ke-register,
   status "activated and running".
3. Matiin network (offline mode di DevTools), reload halaman yang udah
   pernah dibuka — pastikan asset statis (CSS/JS/gambar) tetap muncul,
   dan ada fallback yang jelas (bukan blank page) buat halaman artikel
   yang belum pernah di-cache.
4. Lighthouse audit (tab Lighthouse di DevTools) → run kategori "PWA" —
   cek skor & requirement yang belum kepenuhi (kalau ada).
5. Test di HP beneran (Android Chrome minimal): cek prompt "Add to Home
   Screen" muncul, icon & nama app benar pas di-install.

## Catatan scope (di luar brief ini, opsional ke depan)

PWA basic ini TIDAK bikin navigasi antar halaman jadi mulus tanpa reload
(itu tetap full page reload server-rendered kayak sekarang). Kalau mau
transisi halaman lebih app-like, itu perlu kerjaan terpisah (misal Turbo/
htmx buat partial page load) — di luar scope brief ini.
