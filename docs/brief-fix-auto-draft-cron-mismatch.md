# Brief Teknis: Full Draft Automation gak pernah jalan — cron interval mismatch

**Tanggal:** 8 Agustus 2026
**Area:** Growth Agent — Full Draft Automation (Fase F/H, lihat `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 6)
**Severity:** Bug — fitur silently no-op setiap hari, gak ada error yang kelihatan di UI/log biasa.

## Gejala

Toggle "Nyalakan Full Draft Automation" di halaman Growth Agent (`cms-admin/pages/growth-agent.php`, tab Otomatisasi) sudah AKTIF, jadwal jam sudah dicentang (06, 09, 10, 12, 18 WIB → tersimpan sebagai `schedule_cron = "0 6,9,10,12,18 * * *"`). Tapi dari kemarin sampai sekarang **tidak ada satupun job `auto_draft_article`** yang muncul di `growth_agent_jobs`, di jam manapun yang dijadwalkan.

## Root cause

Trigger satu-satunya untuk `cms_growth_agent_generate_auto_draft_article()` ada di:

`cron/growth_agent_maintenance.php` baris 144-159 (step 8), yang manggil:

```php
$autoDraftResult = cms_growth_agent_maybe_generate_auto_draft($pdo);
```

Fungsi ini (`cms-admin/includes/growth-agent-service.php` baris 6224-6255) punya gate internal:

```php
$cronExpr = trim((string) ($config['schedule_cron'] ?? ''));
if ($cronExpr === '' || !cms_growth_agent_cron_matches($cronExpr)) {
    return ['ran' => false, 'reason' => 'current time does not match schedule_cron', 'job_id' => 0];
}
```

Jadi logic-nya: fungsi ini dipanggil, lalu dia sendiri yang ngecek apakah **waktu saat dipanggil** cocok sama `schedule_cron` yang di-set di UI (06/09/10/12/18). Ini didesain supaya cron luar bisa manggil script maintenance lebih sering dari jadwal auto-draft, dan fungsi ini yang nge-gate presisinya.

Masalahnya: cron job aktual di cPanel yang manggil `growth_agent_maintenance.php` cuma jalan **sekali sehari, jam 04:00**:

```
0 4 * * * /usr/local/bin/php /home/sagagoal/public_html/cron/growth_agent_maintenance.php
```

Jam 04:00 gak pernah ada di daftar jam yang dicentang di UI (06/09/10/12/18) — jadi `cms_growth_agent_cron_matches()` **selalu return false, setiap hari, tanpa terkecuali**. Fungsi selalu skip di step "current time does not match schedule_cron". Ini bukan soal kredensial AI API, bukan soal endpoint salah, dan bukan soal cron "belum di-setup" — cron-nya ada dan jalan, tapi cuma nembak sekali di jam yang gak pernah match jadwal manapun yang bisa dipilih user di UI.

## Kenapa gak kelihatan sebagai error

`cms_growth_agent_maybe_generate_auto_draft()` return `['ran' => false, ...]` dengan reason yang jelas, tapi hasil ini cuma di-echo ke output cron (`echo "...Skipped — {$reason}."`) yang keluarannya masuk ke log cron biasa (kalau ada `>> log 2>&1`, tapi entry cron jam 4 pagi ini gak ada redirect log-nya sama sekali — lihat baris cPanel: `0 4 * * * ... growth_agent_maintenance.php` tanpa `>>`). Jadi output-nya kebuang, gak ada jejak di mana-mana selain kalau devs nge-tail langsung pas cron jalan jam 4 pagi.

## Fix yang disarankan

**Opsi A (paling simpel, sesuai pola project — "jangan bikin sistem baru"):**
Ubah interval cron cPanel yang manggil `growth_agent_maintenance.php` dari sekali sehari jadi tiap jam, supaya `cms_growth_agent_cron_matches()` punya kesempatan ke-check di jam manapun yang user pilih di UI:

```
0 * * * * /usr/local/bin/php /home/sagagoal/public_html/cron/growth_agent_maintenance.php >> /home/sagagoal/logs/cron/growth_agent_maintenance.log 2>&1
```

Ini aman karena step-step lain di `growth_agent_maintenance.php` (step 1-7) sudah pakai pola `*_if_stale()` yang sendirinya idempotent/gak akan re-run kalau data masih fresh — jadi manggil script ini tiap jam gak bikin kerja berlebih di step-step itu. Step 8 (auto-draft) sendiri sudah punya dedup guard (`last_auto_draft_run_at`, baris 6240-6244) jadi aman dipanggil sesering apapun juga.

Sekalian tambahin `>> log 2>&1` yang tadinya gak ada, biar ada jejak log kalau ada masalah lagi ke depannya (bandingkan sama entry cron lain yang sudah punya log redirect).

**Opsi B (kalau devs mau lebih presisi/hemat resource):**
Bikin cron job baru terpisah khusus buat auto-draft, jalan tiap jam, manggil script kecil yang cuma include `cms_growth_agent_maybe_generate_auto_draft($pdo)` tanpa nge-trigger step 1-7 lainnya. Lebih ringan tapi nambah 1 file baru + 1 entry cron — trade-off vs Opsi A yang cuma ubah 1 baris cron.

**Rekomendasi:** Opsi A. Lebih konsisten sama keputusan yang udah dicatat di `docs/GROWTH_AGENT_V2_PROPOSAL.md` baris 122-131 soal "bukan bikin sistem baru, cuma nambah cara motretnya" — filosofi yang sama harusnya dipakai buat fix ini juga.

## Yang perlu dites setelah fix

1. Ganti interval cron di cPanel (Opsi A) atau bikin cron baru (Opsi B).
2. Tunggu satu siklus jam yang match jadwal (misal jam 06:00 atau 09:00 WIB besok, atau set jam dekat buat test cepat kayak yang udah dilakuin hari ini — jam 10:00).
3. Cek `growth_agent_jobs` / tab **Perlu Tindakan** di `growth-agent.php`, harus muncul job baru `job_type = auto_draft_article`, status "draft siap review".
4. Cek log baru (`~/logs/cron/growth_agent_maintenance.log`) buat pastiin step 8 beneran `ran: true` bukan skip lagi.
5. Kalau job muncul tapi gagal (`generation failed: ...`), itu baru kemungkinan soal kredensial AI API / rate limit — beda kelas masalah dari yang di brief ini.

## File yang relevan

- `cron/growth_agent_maintenance.php` (baris 144-159) — trigger step 8
- `cms-admin/includes/growth-agent-service.php` (baris 6224-6255) — gate logic `cms_growth_agent_maybe_generate_auto_draft()`
- `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 6 (baris 792-867) — desain Fase F/G/H
- cPanel → Cron Jobs — entry `0 4 * * *` yang perlu diubah
