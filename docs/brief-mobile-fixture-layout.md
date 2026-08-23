# Brief: Perbaikan tampilan mobile — Jadwal & Skor Pertandingan (fixture list)

## Konteks

Operator laporan tampilan mobile untuk halaman jadwal/skor pertandingan
(`football.php`, kemungkinan juga `basket.php`/`f1.php` yang reuse markup
sama) terlihat "terputus"/tidak presisi di layar HP — screenshot
menunjukkan baris pertandingan (nama tim, skor, logo) terasa mepet ke tepi
layar dan tidak rapi dibanding referensi (goal.com), yang punya kartu
per-pertandingan dengan spacing/rounded corner yang jelas dan enak dilihat.

## Yang sudah dicek (kode CSS)

File: `assets/css/site.css`

- `.crypto-container` (baris ~150): `max-width: 1180px; padding: 0 24px;`
  — TIDAK ada override untuk mobile, jadi padding kiri-kanan tetap 24px di
  semua ukuran layar. Di layar 375px lebar, ini nyisain ~327px buat
  konten.
- `.fixture-card` (baris ~2365): `grid-template-columns: 1fr 88px 1fr;
  gap: 16px; padding: 16px 24px;` — di breakpoint `@media (max-width:
  640px)` (baris ~2463) di-kecilin jadi `grid-template-columns: 1fr 64px
  1fr; gap: 8px; padding: 14px 12px;`.
- `.fixture-card__team span` sudah punya `min-width: 0; overflow: hidden;
  text-overflow: ellipsis; white-space: nowrap;` — truncate nama tim
  panjang, seharusnya tidak overflow keluar grid.
- `.fixture-league-group__list` (baris ~2358) punya `overflow: hidden;`
  di container-nya.
- `box-sizing: border-box` sudah global (`* , *::before, *::after` baris
  118).
- `body.crypto-theme` (atau parent-nya) punya `overflow-x: hidden`
  (baris ~127).

Secara matematis di atas kertas grid-nya seharusnya muat, tapi
operator konsisten screenshot nunjukin tampilan yang "gak presisi"/kepotong
di HP asli. Kemungkinan penyebab yang BELUM tercek (perlu devs investigasi
langsung pakai Chrome DevTools device toolbar / HP fisik, bukan cuma baca
CSS statis):

1. **Long team name di kompetisi tertentu** (contoh dari screenshot:
   "Villarreal", "Atletico Madrid", MLS club names panjang kayak "Vancouver
   Whitecaps", "Colorado Rapids") — kemungkinan ellipsis-nya kerja tapi
   HASIL visualnya tetap kerasa sempit/gak nyaman dibaca di kolom ~100px.
2. **Row horizontal date-picker** ("KAM 20 / JUM 21 / SAB 22 / HARI INI 23
   / SEN 24 / SEL 25 / RAB 26") yang keliatan di atas fixture list —
   perlu dicek apakah container-nya `overflow-x: auto` dengan scroll
   independen yang benar, atau malah bikin parent ikut melebar
   (row ini class-nya belum ketemu lewat grep cepat di football.php,
   kemungkinan markup-nya di file/include lain — perlu devs cari
   sendiri, mungkin generated dari partial/component terpisah).
3. **Row MLS yang sangat panjang** (12 pertandingan berturut-turut tanpa
   date-header) di screenshot kedua — cek apakah ada elemen dalam row itu
   yang overflow horizontal secara spesifik, beda dari row liga lain.
4. Kemungkinan viewport meta tag (`<meta name="viewport">`) di
   `includes/site-header.php` perlu dicek — kalau ada `width=device-width`
   yang hilang/salah, seluruh halaman bisa ke-render ukuran desktop-scaled
   sehingga SEMUA elemen "kelihatan" presisi di CSS tapi user liat versi
   zoomed-out di HP asli.

## Referensi yang diminta operator

Operator explicitly minta referensi **goal.com** punya tampilan fixture
list mobile — ciri khasnya:
- Setiap pertandingan dalam card/row dengan padding lega, jelas
  batasnya (border/shadow tipis antar row atau rounded card terpisah).
- Kolom skor/waktu di tengah tetap konsisten lebar meski nama tim beda
  panjang.
- Logo tim ukuran konsisten, tidak menyusut kalau nama tim panjang.
- Tidak ada elemen yang terasa "mepet"/rapat ke tepi kartu.

## Yang perlu dikerjakan devs

1. Buka halaman `/sepak-bola` (atau slug jadwal football) di Chrome
   DevTools dengan device toolbar (iPhone SE 375px DAN iPhone 14 Pro Max
   430px, dua ekstrem ukuran umum) — reproduksi dulu masalah yang
   dilaporkan operator sebelum ubah apapun.
2. Inspect element pada row yang kelihatan terpotong di screenshot
   (contoh: baris Espanyol vs Real Madrid, atau baris-baris MLS) — cek
   actual computed width tiap kolom grid, apakah ada child element yang
   overflow parent-nya (`scrollWidth > clientWidth` di DevTools console
   gampang buat ngecek: `document.querySelector('.fixture-league-group__list').scrollWidth`
   dibanding `.clientWidth`).
3. Kalau ketemu elemen spesifik yang overflow (kemungkinan besar dari poin
   #2/#3 di atas), fix breakpoint tambahan di bawah 640px (misal
   `@media (max-width: 380px)`) dengan grid/font lebih kecil lagi, ATAU
   redesign card mobile-nya ikut pola goal.com (logo+skor di tengah lebih
   dominan, nama tim di bawah logo bukan di samping — biar gak
   perlu grid 3 kolom sempit sama sekali).
4. Screenshot hasil sebelum/sesudah di kedua ukuran viewport (375px,
   430px) buat verifikasi ke operator sebelum deploy.

## File yang relevan

- `assets/css/site.css` — `.fixture-card`, `.fixture-league-group__list`,
  `.crypto-container`, breakpoint `@media (max-width: 640px)` (baris
  ~2358-2470)
- `football.php` — markup fixture list (baris ~184-198 buat struktur
  league group)
- `basket.php`, `f1.php` — kemungkinan reuse `.fixture-card` yang sama,
  cek juga kalau perbaikan grid/breakpoint di atas berdampak ke sini
- `includes/site-header.php` — cek viewport meta tag (poin #4 di atas)
