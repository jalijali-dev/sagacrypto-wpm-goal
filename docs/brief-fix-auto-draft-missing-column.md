# Brief Teknis: Full Draft Automation gagal tiap run — kolom `last_auto_draft_run_at` belum ada di DB production

**Tanggal:** 8 Agustus 2026
**Area:** Growth Agent — Full Draft Automation (Fase F/H, lihat `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 6)
**Severity:** Bug — fitur silently gagal setiap run cron, sejak fix cron interval (`docs/brief-fix-auto-draft-cron-mismatch.md`) di-deploy. Belum pernah sekalipun berhasil generate draft.

## Konteks

Brief sebelumnya (`docs/brief-fix-auto-draft-cron-mismatch.md`) soal cron cPanel yang cuma jalan sekali sehari sudah di-fix — dikonfirmasi cPanel sekarang `0 * * * *` dengan log redirect ke `~/logs/cron/growth_agent_maintenance.log`. Cron sekarang jalan tiap jam sesuai rencana. Tapi setelah dicek log production, step 8 (auto-draft) masih selalu skip, dengan error baru yang beda dari sebelumnya.

## Gejala

Log `~/logs/cron/growth_agent_maintenance.log` (dicek langsung dari cPanel File Manager, 8 Agustus ~18:05 WIB) menunjukkan, di **setiap** run cron sejauh ini:

```
[growth_agent_maintenance] auto_draft_article: Skipped — exception: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'last_auto_draft_run_at' in 'field list'.
```

Tidak ada satupun job `auto_draft_article` yang pernah tercatat di `growth_agent_jobs` sejak fitur ini dinyalakan.

## Root cause

Kolom `gsc_settings.last_auto_draft_run_at` memang **ada di kode migrasi lazy** — `cms_gsc_ensure_schema()` di `cms-admin/includes/gsc-api.php` baris 79:

```php
cms_ensure_column($pdo, 'gsc_settings', 'last_auto_draft_run_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `last_trending_headlines_refresh_at`');
```

Tapi fungsi yang benar-benar memakai kolom ini, `cms_growth_agent_maybe_generate_auto_draft()` (`cms-admin/includes/growth-agent-service.php` baris 6252-6293), **tidak pernah memanggil `cms_gsc_ensure_schema($pdo)`** sebelum baca/tulis kolom itu:

```php
$settings = cms_gsc_get_settings($pdo);              // baris 6278 — SELECT *, gak error meski kolom belum ada
$lastRun = $settings['last_auto_draft_run_at'] ?? null;
...
$pdo->prepare('UPDATE gsc_settings SET last_auto_draft_run_at = NOW() ORDER BY id ASC LIMIT 1')->execute();  // baris 6285 — INI yang meledak
```

Bandingkan dengan pola yang benar di fungsi tetangganya, `cms_growth_agent_inspect_priority_urls()` (baris ~4467), yang eksplisit manggil `cms_gsc_ensure_schema($pdo)` sebelum pakai kolom-kolom GSC.

Kenapa kolom itu belum ke-create di production padahal kodenya lazy-migration: `cron/growth_agent_maintenance.php` step 1 cuma manggil `cms_growth_agent_ensure_schema()` (fungsi lain, di file yang sama, urus tabel `growth_agent_jobs` dkk — **bukan** `gsc_settings`). `cms_gsc_ensure_schema()` yang punya kolom ini baru ke-trigger dari path lain (mis. buka halaman `gsc-settings.php`, atau `cms_gsc_fetch_and_cache()` pas GSC fetch beneran jalan — bukan pas di-skip karena belum stale). Di production, kombinasi run cron ini kebetulan belum pernah lewat salah satu path itu, jadi kolomnya belum ke-create sampai sekarang.

Singkatnya: dua bug kecil yang saling ketemu — (1) fungsi auto-draft gak self-guard schema-nya sendiri, (2) cron path yang jalan tiap jam juga gak nyentuh `cms_gsc_ensure_schema()`.

## Fix yang disarankan

**Opsi A (direkomendasikan — konsisten sama pola yang sudah ada di `cms_growth_agent_inspect_priority_urls()`):**

Tambah satu baris di awal try-block `cms_growth_agent_maybe_generate_auto_draft()` (`growth-agent-service.php` baris ~6255, setelah `require_once __DIR__ . '/gsc-api.php';`):

```php
require_once __DIR__ . '/gsc-api.php';
cms_gsc_ensure_schema($pdo);   // <-- baris baru
$config = cms_gsc_get_opportunity_thresholds($pdo)['auto_draft_automation'] ?? [];
```

Ini self-contained — fungsi jadi gak bergantung urutan/kebetulan path lain udah jalan duluan. Konsisten sama pola defensif yang udah dipakai di fungsi tetangganya persis untuk alasan yang sama.

**Opsi B (tambahan, bukan pengganti):**
Tambah `cms_gsc_ensure_schema($pdo)` juga di step 1 `cron/growth_agent_maintenance.php`, sejajar dengan `cms_growth_agent_ensure_schema($pdo)` yang sudah ada, supaya semua kolom `gsc_settings` ke-provision di awal run sebelum step manapun butuh. Ini lebih menyeluruh tapi Opsi A tetap perlu tetap ada di fungsinya sendiri (defense in depth, sama kayak `cms_growth_agent_inspect_priority_urls()`), jadi Opsi B sifatnya pelengkap, bukan alternatif.

**Rekomendasi:** Opsi A wajib, Opsi B opsional kalau devs mau lebih rapi.

## Yang perlu dites setelah fix

1. Deploy fix (Opsi A minimal) ke production.
2. Tunggu satu siklus jam yang match `schedule_cron` di UI (termasuk jam 18:00 WIB yang sudah dicentang), atau set jam dekat buat test cepat.
3. Cek `~/logs/cron/growth_agent_maintenance.log` — step 8 harus baca `ran: true, reason: generated` (atau `generation failed: ...` kalau ada masalah lain di lapisan AI, itu beda kelas masalah).
4. Cek tab **Perlu Tindakan** di `growth-agent.php` — harus muncul job baru `job_type = auto_draft_article`, status "draft siap review".
5. Kalau job muncul: cek judul artikel, apakah `cover_image_path` terisi (fallback ke `/assets/img/logo.png` kalau fix gambar default sudah di-deploy), dan badge peringatan (SEO-G0, title-vs-headline) — ini bukti pertama end-to-end pipeline auto-draft beneran jalan dari scrape sampai jadi draft.

## File yang relevan

- `cms-admin/includes/growth-agent-service.php` baris 6252-6293 — `cms_growth_agent_maybe_generate_auto_draft()`, lokasi fix Opsi A
- `cms-admin/includes/growth-agent-service.php` baris ~4467 — `cms_growth_agent_inspect_priority_urls()`, contoh pola yang benar
- `cms-admin/includes/gsc-api.php` baris 35-80 — `cms_gsc_ensure_schema()`, sumber kolom `last_auto_draft_run_at`
- `cron/growth_agent_maintenance.php` baris ~44-52 (step 1) — lokasi fix Opsi B kalau mau ditambahkan
- `~/logs/cron/growth_agent_maintenance.log` (production, via cPanel) — bukti error, dicek 8 Agustus 2026 ~18:05 WIB
- `docs/brief-fix-auto-draft-cron-mismatch.md` — brief sebelumnya, root cause berbeda (cron interval), sudah fixed
