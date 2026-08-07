# Growth Agent — Panduan Coba Semua Fitur

> Cheat-sheet buat operator (Donnie) ngetes tiap fitur Growth Agent
> setelah rilis 6 Agustus 2026 (Fase A-E + Article Idea collision-avoidance).
> Baca `docs/GROWTH_AGENT_V2_PROPOSAL.md` kalau butuh detail teknis/alasan
> desain tiap fitur — dokumen ini murni langkah klik, bukan penjelasan
> arsitektur.

Terakhir diperbarui: 7 Agustus 2026

---

## 0. Sebelum mulai

Login ke admin panel: `https://sagagoal.com/cms-admin/`

Semua fitur Growth Agent ada di grup sidebar **AI Management**:
- **Growth Agent** (`growth-agent.php`) — pusat kendali, 4 tab
- **SEO Intelligence** (`seo-intelligence.php`) — Keyword Expansion & Topic Cluster

---

## 1. Growth Agent — halaman utama

Buka sidebar → **AI Management → Growth Agent**.

4 tab di atas: **Perlu Tindakan**, **Kesehatan Teknis**, **Data & Performa**,
**Agent & Setelan**. Semua langkah di bawah ini nunjuk ke salah satu tab ini.

---

## 2. Tes Article Idea (fitur baru — collision avoidance)

📍 Tab **Perlu Tindakan** → panel **Job Terbaru**

Ini fitur paling baru rilis, prioritas nomor satu buat dicek.

- [ ] Cari job dengan `job_type = gsc_article_idea` yang baru muncul.
- [ ] Kalau ada artikel Sagagoal yang topiknya mirip → harus ada badge
      **⚠ SEO-G0**.
- [ ] Kalau judul yang diusulin AI kebetulan mirip headline dari
      detik.com/CNN Indonesia → harus ada badge peringatan **judul mirip
      headline sumber**. **Ini yang paling penting dicek** — bukti fitur
      barunya beneran jalan.
- [ ] Kalau belum ada job baru: tunggu cron jam 04:00, atau cek tombol
      trigger manual di halaman ini.

---

## 3. Keyword Expansion Agent

📍 Sidebar → **AI Management → SEO Intelligence**

- [ ] Klik tombol **"Scan Keyword Expansion"**.
- [ ] Tunggu AI generate topik baru.
- [ ] ⚠️ **Baca dulu topiknya sebelum approve** — fitur ini belum pernah
      diuji pakai AI sungguhan di production, cuma pakai data mock waktu
      testing devs.

---

## 4. Internal Linking Agent

📍 `growth-agent.php` → tombol **"Scan Internal Linking"**

- [ ] Klik scan, tunggu hasil.
- [ ] Hasilnya masuk ke halaman terpisah `internal-link-review.php`.
- [ ] Cek anchor text + kalimat sekitarnya dulu sebelum klik **Apply**.

---

## 5. Technical SEO Auditor

📍 Tab **Kesehatan Teknis**

- [ ] Klik tombol cek konten (alt text + schema markup).
- [ ] Klik tombol cek PSI (kecepatan halaman) — proses ini agak lama
      (10-30 detik per URL), wajar.
- [ ] Ini murni laporan — gak ada yang perlu di-approve/apply.

---

## 6. Daftar Artikel Berpotensi Tinggi

📍 Tab **Data & Performa** → di antara panel "Artikel Terpopuler" dan
"Feedback / Sebelum-Sesudah"

- [ ] Cek tabelnya muncul (butuh data GSC yang cukup dulu).
- [ ] Laporan otomatis, gak ada tombol aksi.

---

## 7. Measurement Loop

📍 Tab **Data & Performa** → panel **Feedback / Sebelum-Sesudah**

- ⚠️ **Belum bakal nampilin apa-apa sekarang.** Butuh job yang udah
  28 hari sejak di-Apply — yang paling tua baru ~2 hari (per 6 Agustus).
  **Cek lagi mulai awal September.**

---

## 8. Fase E — Mode Otonom (toggle)

📍 Tab **Perlu Tindakan** → panel toggle **Internal Linking Agent**

- [ ] Pastikan statusnya **NONAKTIF**.
- 🚫 **Jangan dinyalain dulu** — nunggu data Measurement Loop cukup
  (lihat § 7) sebelum ada keputusan nyalain apa nggak.

---

## 9. Notifikasi Telegram (n8n)

- [ ] Pastiin bot `sagagoal_bot` masih ngirim digest mingguan.
- [ ] Kalau ada masalah "undefined"/gak kekirim: cek URL di node
  **HTTP Request** workflow n8n `SAGAGOAL - Growth Agent Digest` —
  harus `https://sagagoal.com/cms-admin/api/growth-agent-digest.php`
  (bukan domain lama `wpm.sagagoal.com`).
- [ ] Cek link "buka admin" di isi pesan Telegram beneran ngarah ke
  `https://sagagoal.com/cms-admin/pages/growth-agent.php` (bukan kepotong
  jadi `sagagoal.com/pages/...` tanpa `/cms-admin/`) — kalau kepotong,
  berarti `BASE_URL` di `config/app.php` server belum diupdate, lihat
  `docs/DECISIONS.md` entri 2026-07-15 (update).
- [ ] Riwayat: pernah rusak 6-7 Agustus 2026 gara-gara pindah domain,
  udah diperbaiki + di-Publish ulang di n8n.

---

## Kalau ada yang error

Screenshot atau ceritain gejalanya (halaman mana, tombol apa, pesan
error-nya gimana) — bisa didiagnosis dari situ, gak perlu tau detail
teknis di baliknya.
