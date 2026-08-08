# Brief Teknis: Rate limit harian (fleksibel) untuk Full Draft Automation

**Tanggal:** 8 Agustus 2026
**Area:** Growth Agent — Full Draft Automation (Fase F, lihat `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 6)
**Requested by:** Owner project (via Raja) — bukan soal biaya AI (budget bukan concern), murni butuh kontrol
fleksibel jumlah draft/hari yang independen dari jumlah jam yang dicentang di jadwal. **Dikejar cepat.**

## Kenapa dibutuhkan

Sekarang (setelah fix `docs/brief-fix-auto-draft-cron-mismatch.md`), jumlah draft/hari SATU-SATUNYA dikontrol
lewat berapa jam yang dicentang di jadwal (`hours[]` di tab Otomatisasi → Full Draft Automation). Kalau owner
mau generate lebih sering di jam-jam tertentu (misal tiap jam kerja) tapi tetap mau batas keras "maks N draft
per hari" biar nggak kebanjiran job yang perlu direview manual, itu nggak bisa — jumlah jam checklist = jumlah
draft, nggak bisa dipisah.

Field ini SUDAH direncanakan di proposal buat Fase G (auto-publish, § 6 baris 842-846:
`{ enabled: false, rate_limit_per_day: 2 }`) tapi belum dibangun buat Fase F (draft-only) yang sudah jalan
sekarang. Brief ini scope-nya CUMA nambah field yang sama ke Fase F — bukan Fase G, bukan auto-publish.

## Yang mau dibangun

Satu field baru: `auto_draft_automation.max_drafts_per_day` (integer, **default `3`** — bukan unlimited, biar
kalau operator belum sempat set manual, sistem tetap punya batas keras bawaan yang masuk akal, bukan
kepercayaan penuh ke jumlah jam yang dicentang). Operator tetap bebas ubah angkanya dari UI ke berapa pun
(termasuk isi `0` secara eksplisit kalau memang mau unlimited — pilihan sadar dari operator, bukan default
sistem). `cms_growth_agent_maybe_generate_auto_draft()` berhenti generate draft baru begitu jumlah job
`auto_draft_article` yang dibuat HARI INI (server time) sudah menyentuh angka itu — sisa jam yang dijadwalkan
hari itu otomatis di-skip dengan reason yang jelas, bukan generate terus tanpa batas.

**Kenapa default 3, bukan 0:** field ini baru pertama kali ada di Fase F — belum ada track record berapa
draft/hari yang wajar buat kapasitas review manual editor. 3/hari itu setara jam kerja normal (masih di bawah
jumlah slot jadwal default lama, 06/12/18 = 3 jam), cukup buat validasi kualitas tanpa bikin backlog review
menumpuk kalau operator lupa nge-set field ini setelah upgrade. Operator kapan pun bisa naikin/turunin dari UI
begitu udah nyaman sama kualitas draftnya.

## Lokasi kode yang perlu diubah

### 1. Config default — `cms-admin/includes/gsc-api.php` sekitar baris 1377-1394

Tambah key baru ke array `auto_draft_automation`:

```php
'auto_draft_automation' => [
    'enabled' => false,
    'schedule_cron' => '0 6,12,18 * * *',
    'source_urls' => [...],
    // BARU:
    'max_drafts_per_day' => 3, // default aman, bukan unlimited — N = stop setelah N draft sukses/gagal
                               // hari ini; operator boleh isi 0 dari UI kalau memang mau unlimited
],
```

### 2. Gate logic — `cms-admin/includes/growth-agent-service.php`,
`cms_growth_agent_maybe_generate_auto_draft()` baris 6224-6255

Tambah SATU pengecekan baru, setelah cek `schedule_cron` (baris 6234-6237) dan SEBELUM cek
"already ran for this exact minute" (baris 6239-6244) — urutan gate sama seperti pola existing (paling murah
dicek duluan, gate mahal belakangan):

```php
$maxPerDay = (int) ($config['max_drafts_per_day'] ?? 0);
if ($maxPerDay > 0) {
    $todayCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM growth_agent_jobs
          WHERE job_type = 'auto_draft_article' AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    if ($todayCount >= $maxPerDay) {
        return ['ran' => false, 'reason' => "daily limit reached ({$todayCount}/{$maxPerDay})", 'job_id' => 0];
    }
}
```

Catatan: hitung SEMUA job hari ini terlepas status (`succeeded` maupun `failed`) — job yang gagal generate
tetap kepake 1 slot AI call/attempt, jadi tetap dihitung. Kalau nanti mau dibedain (cuma hitung yang
`succeeded`), itu keputusan produk terpisah, bukan bug — catat di `docs/DECISIONS.md` kalau diubah.

Pastikan kolom yang dipakai (`created_at`, `status`, `job_type`) match skema `growth_agent_jobs` yang
sebenarnya — cek `cms_growth_agent_log_job()` buat nama kolom persis kalau beda dari asumsi di atas.

### 3. UI — `cms-admin/pages/growth-agent.php`

**POST handler**, di blok `if ($action === 'auto_draft_automation_save')` sekitar baris 377-427 — tambah parse
& validasi field baru, sisipkan ke `cms_gsc_set_opportunity_threshold_key()` call (baris 417-421):

```php
// Textbox, bukan <input type="number"> — validasi range HARUS di server, jangan andalkan
// browser. Non-digit/kosong jatuh ke default 3 lewat (int) cast (string kosong/non-numerik
// jadi 0, lalu ke-clamp min 0 di bawah — tambahkan fallback eksplisit kalau mau beda dari 0).
$maxPerDay = (int) ($_POST['max_drafts_per_day'] ?? 3);
$maxPerDay = max(0, min(1000, $maxPerDay));

$saved = cms_gsc_set_opportunity_threshold_key($pdo, 'auto_draft_automation', [
    'enabled' => $turnOn,
    'schedule_cron' => $scheduleCron,
    'source_urls' => $sourceUrls,
    'max_drafts_per_day' => $maxPerDay, // BARU
]);
```

**Form render**, sekitar baris 2088-2141 — baca config (dekat baris 2088-2095):

```php
$autoDraftMaxPerDay = (int) ($autoDraftConfig['max_drafts_per_day'] ?? 3);
```

Tambah field baru di form, taruh setelah blok jadwal jam (setelah baris 2129, sebelum textarea URL sumber di
baris 2131) — pola input number simpel, konsisten sama input lain di halaman ini:

```html
<label class="field">
    <span>Batas maksimal draft per hari</span>
    <input type="text" inputmode="numeric" pattern="[0-9]*" name="max_drafts_per_day"
           value="<?= (int) $autoDraftMaxPerDay ?>" style="width:120px;">
    <small class="muted">
        Default 3/hari. Isi angka 0–1000 (0 = tidak dibatasi, tidak direkomendasikan sampai kualitas
        draft AI sudah divalidasi beberapa minggu). Sisa jadwal hari itu otomatis di-skip begitu
        batas tercapai, terlepas dari berapa banyak jam yang dicentang di atas.
    </small>
</label>
```

## Yang perlu dites setelah selesai

1. Set `max_drafts_per_day = 1`, jadwal tetap banyak jam (misal 06,09,10,12,18) — pastikan cuma job PERTAMA
   yang generate hari itu, sisanya di-skip dengan reason `daily limit reached (1/1)` (cek lewat manual run
   `php cron/growth_agent_maintenance.php` di jam-jam berikutnya, atau tunggu jadwal).
2. Set `max_drafts_per_day = 0` — pastikan behavior balik ke default lama (generate di setiap jam
   terjadwal, tanpa batas tambahan).
3. Cek form: value ke-render balik dengan benar setelah save (nggak reset ke 0 tiap reload).
4. Cek DATE(created_at) pakai timezone server yang sama dengan `cms_growth_agent_cron_matches()` — kalau
   server timezone beda dari WIB, "hari ini" bisa salah hitung pas lewat tengah malam. Cross-check dengan
   kolom `WAKTU` yang sudah tampil benar di tab Job Terbaru (harusnya konsisten).

## File yang relevan

- `cms-admin/includes/gsc-api.php` (~baris 1377) — config default
- `cms-admin/includes/growth-agent-service.php` (~baris 6224-6255) — gate logic
- `cms-admin/pages/growth-agent.php` (~baris 377-427 POST handler, ~baris 2088-2141 form render)
- `docs/GROWTH_AGENT_V2_PROPOSAL.md` § 6 (baris 842-846) — precedent desain field ini untuk Fase G, dipakai
  sebagai referensi nama field (`rate_limit_per_day` di proposal, dipakai `max_drafts_per_day` di brief ini
  biar nggak ketuker sama field yang sama di Fase G nanti — kalau devs mau samain nama, catat keputusannya di
  `docs/DECISIONS.md`)
