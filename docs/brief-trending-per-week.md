# Brief: Ganti "Sedang Tren" dari is_featured+total views jadi trending per-minggu

## Konteks

Widget "Sedang Tren" di homepage (`index.php` baris ~66-75) narik artikel
dengan query ini:

```sql
SELECT p.*, a.name AS author_name
FROM pages p
LEFT JOIN admins a ON a.admin_id = p.author_id
WHERE p.status = 'published' AND p.is_featured = 1
ORDER BY p.views DESC, p.published_at DESC
LIMIT 4
```

Operator laporan widget ini gak update — isinya artikel dari sebulan lalu
terus, padahal ada artikel baru yang lagi rame dibaca hari ini.

## Root cause

Dua hal gabungan:

1. Kandidatnya cuma artikel yang di-flag `is_featured = 1` manual di admin
   — kalau operator lupa update flag ini, artikel baru gak akan pernah
   masuk radar sama sekali, seberapa pun ramenya.
2. Diurutin berdasarkan `views` — ini kolom counter TOTAL SEPANJANG WAKTU
   (lihat `includes/site-bootstrap.php` baris ~1470:
   `UPDATE pages SET views = views + 1 WHERE page_id = :id`, dipanggil
   tiap kali artikel dibuka, TANPA timestamp per-kejadian). Artikel lama
   yang udah numpuk views berbulan-bulan otomatis SELALU menang lawan
   artikel baru yang baru punya sedikit views, walau artikel barunya lagi
   viral hari ini.

**Belum ada tabel yang nyimpen breakdown views per hari/minggu** — jadi
"trending per minggu" gak bisa dihitung dari skema yang ada sekarang,
perlu tabel baru + perubahan logic increment views.

## Yang perlu dikerjain

### 1. Tabel baru — `page_view_daily`

```sql
CREATE TABLE page_view_daily (
    page_id INT UNSIGNED NOT NULL,
    view_date DATE NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (page_id, view_date),
    KEY idx_view_date (view_date)
) ENGINE=InnoDB;
```

Satu row per (artikel, hari), gampang di-`SUM()` buat rentang waktu
berapa pun (7 hari, 30 hari, dst) tanpa perlu scan tabel besar per-event.

### 2. Ubah logic increment di `includes/site-bootstrap.php` (~baris 1470)

Sekarang:
```php
$pdo->prepare('UPDATE pages SET views = views + 1 WHERE page_id = :id')->execute(['id' => $pageId]);
```

Jadi (tambah, bukan ganti — `pages.views` tetap dipertahankan buat total
lifetime views yang udah dipakai di tempat lain):
```php
$pdo->prepare('UPDATE pages SET views = views + 1 WHERE page_id = :id')->execute(['id' => $pageId]);
$pdo->prepare(
    'INSERT INTO page_view_daily (page_id, view_date, views)
     VALUES (:id, CURDATE(), 1)
     ON DUPLICATE KEY UPDATE views = views + 1'
)->execute(['id' => $pageId]);
```

Pastiin `cms_ensure_table()` (schema-guard.php, pola yang sama kayak
tabel-tabel lain di proyek ini) dipanggil buat `page_view_daily` di titik
yang sama kayak tabel lain di-ensure (kemungkinan di site-bootstrap.php
juga, deket fungsi ini, biar auto-create pas pertama kali dipanggil,
gak perlu migration manual).

### 3. Ganti query trending di `index.php` (~baris 66-75)

Dari `is_featured`+`views` total, jadi SUM `page_view_daily.views` 7 hari
terakhir:

```sql
SELECT p.*, a.name AS author_name,
       COALESCE(SUM(pvd.views), 0) AS views_7d
FROM pages p
LEFT JOIN admins a ON a.admin_id = p.author_id
LEFT JOIN page_view_daily pvd
       ON pvd.page_id = p.page_id
      AND pvd.view_date >= (CURDATE() - INTERVAL 7 DAY)
WHERE p.status = 'published'
GROUP BY p.page_id
HAVING views_7d > 0
ORDER BY views_7d DESC, p.published_at DESC
LIMIT 4
```

Catatan: `is_featured` DIHAPUS dari WHERE clause — semua artikel published
jadi kandidat, gak dibatasin ke yang di-flag manual doang. Kalau operator
masih mau ada cara "pin manual" artikel tertentu ke widget ini (misal buat
artikel sponsor/campaign), itu perlu dibahas terpisah — bisa ditambah
`is_featured` sebagai TIE-BREAKER tambahan di ORDER BY, bukan filter WHERE
yang membatasi kandidat dari awal.

### 4. Fallback kalau tabel baru masih kosong (baru pertama kali deploy)

Query `HAVING views_7d > 0` otomatis bikin widget kosong total kalau
`page_view_daily` belum ada datanya sama sekali (hari pertama abis
deploy). Perlu fallback: kalau hasil query di atas kosong, jalanin query
lama (is_featured+total views) sebagai cadangan, biar widget gak
tiba-tiba blank pas baru deploy.

### 5. (Opsional, follow-up terpisah) Housekeeping `page_view_daily`

Tabel ini nambah 1 row per artikel per hari yang ada view-nya — growth-nya
wajar (gak seperti event-per-row), tapi kalau mau, bisa tambah cron
cleanup buat hapus row lebih tua dari misal 90 hari, konsisten sama pola
retention yang udah ada di `cms_growth_agent_cleanup_old_jobs()` (90 hari
juga).

## Testing

1. Deploy, buka beberapa artikel beda-beda buat generate data di
   `page_view_daily` selama 1-2 hari.
2. Cek `SELECT * FROM page_view_daily ORDER BY view_date DESC LIMIT 20;`
   — pastiin row baru nambah tiap hari, bukan nimpa.
3. Cek widget "Sedang Tren" di homepage — pastiin urutannya berubah
   ngikutin artikel yang beneran baru dibaca, bukan yang lama terus.
4. Test fallback: kosongin manual (`TRUNCATE page_view_daily` di
   staging/local, JANGAN di production) → pastiin widget tetap nampilin
   sesuatu (fallback ke query lama), gak blank.

## File yang relevan

- `index.php` (baris ~66-75) — query trending sidebar
- `includes/site-bootstrap.php` (baris ~1470) — logic increment views
- `cms-admin/includes/schema-guard.php` — pola `cms_ensure_table()` yang
  dipakai buat auto-create tabel baru
