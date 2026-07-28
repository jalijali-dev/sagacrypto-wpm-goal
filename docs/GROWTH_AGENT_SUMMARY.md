# Growth Agent + SEO/GSC — Ringkasan Implementasi

> Ringkasan buat tim/atasan. Untuk detail teknis lengkap tiap perubahan,
> selalu rujuk `docs/ROADMAP.md` § Done (entri 27–28 Jul 2026) dan desain
> awal di `docs/GROWTH_AGENT_SEO_ROADMAP.md`.

Terakhir diperbarui: **28 Juli 2026**

---

## Apa ini dan kenapa dibangun

Growth Agent adalah sistem yang menghubungkan data Google Search Console
(GSC) dengan Content Agent (AI penulis artikel) yang sudah ada, supaya
proses "cari peluang SEO → putuskan tindakan → hasilkan artikel/perbaikan →
ukur hasilnya" gak lagi manual sepenuhnya. Goal-nya bukan menjamin ranking
cepat naik, tapi mempercepat proses menemukan & menindaklanjuti peluang SEO
berbasis data asli, dengan operator (manusia) tetap pegang kendali penuh di
setiap keputusan penting — tidak ada publish otomatis, tidak ada tulis ulang
artikel otomatis.

## Cara kerja singkat

```
Data GSC (klik, impression, posisi per artikel & keyword)
        ↓
Opportunity Engine — hitung peluang SEO (murni rule/matematika, TANPA AI)
        ↓
Growth Agent — 3 tipe rekomendasi (pakai AI, tapi cuma buat menyusun saran,
                bukan mengeksekusi)
        ↓
Action Queue — operator review, approve/reject/tandai-legacy
        ↓
Artikel/perbaikan (selalu berstatus draft — publish tetap manual)
        ↓
Feedback Loop — ukur before/after di GSC per artikel
```

## Status: SELESAI (28 Juli 2026)

Seluruh scope MVP yang direkomendasikan (`docs/GROWTH_AGENT_SEO_ROADMAP.md`)
sudah diimplementasikan dan ditest end-to-end (bukan cuma lint/simulasi —
setiap fitur diverifikasi lewat pemakaian nyata di admin panel dan, untuk
integrasi GSC, lewat panggilan API asli ke Google).

| # | Fitur | Fungsi |
|---|---|---|
| — | **GSC Collector** | Ambil data performa (klik/impression/CTR/posisi) dari Search Console, otomatis, credential terenkripsi |
| — | **Opportunity Engine** | Deteksi peluang SEO murni berbasis rule/skor — bukan AI, jadi hasilnya konsisten & bisa diaudit |
| — | **Growth Agent (AI)** | Ubah data peluang jadi rekomendasi konkret: saran meta title/description, saran perbaikan artikel, ide artikel baru |
| — | **Action Queue** | Semua rekomendasi masuk antrian review — operator yang approve/reject, bukan AI yang eksekusi sendiri |
| 1 | **Content Agent Adapter** | Ide artikel yang di-approve otomatis jadi draft artikel beneran (bukan cuma teks saran yang harus di-copy manual) |
| 2 | **Indexing Workflow** | Cek status index artikel di Google (via URL Inspection API resmi — bukan Indexing API, sesuai batasan Google), otomatis kasih checklist kalau ada masalah |
| 3 | **Agent Memory** | Sistem belajar pola historis (kata kunci/artikel yang terbukti berhasil) buat jadi konteks tambahan saat AI bikin rekomendasi baru |
| 4 | **Feedback Loop** | Bandingkan performa GSC sebelum vs sesudah sebuah tindakan diterapkan — biar kelihatan mana rekomendasi yang beneran berdampak |
| 5 | **Cannibalization + Content Decay** | Deteksi artikel yang performanya menurun (perlu di-refresh) dan kata kunci yang "rebutan" antar beberapa artikel (perlu keputusan manual konsolidasi) |

## Prinsip keamanan yang dijaga di semua fitur

- **AI cuma menyarankan, tidak pernah eksekusi otomatis.** Publish artikel,
  perbaikan konten, dan keputusan konsolidasi selalu lewat approval manusia.
- **Artikel baru selalu berstatus draft**, tidak pernah langsung published.
- **Tidak pernah pakai Google Indexing API** untuk artikel biasa (API itu
  cuma untuk lowongan kerja/livestream) — cukup URL Inspection + sitemap.
- **Kalau data belum cukup buat kesimpulan** (misal before/after belum ada
  cukup histori), sistem menandai "data belum cukup", bukan memaksakan
  angka yang menyesatkan.
- Semua tabel baru dibuat otomatis saat halaman terkait pertama kali dibuka
  (bukan lewat file migrasi terpisah) — konsisten dengan cara kerja sistem
  lain di CMS ini.

## Yang perlu diketahui / catatan jujur

- **Agent Memory dan sebagian pengujian lain sempat pakai data simulasi**
  (seed manual) karena data GSC asli di lingkungan pengembangan masih
  sedikit — logic-nya sudah diverifikasi benar secara matematis, tapi pola
  nyata baru akan mulai kelihatan setelah data GSC asli terkumpul cukup
  lama (butuh beberapa minggu pemakaian).
- **Jalur AI untuk "Content Decay" (Generate rekomendasi artikel menurun)
  baru diverifikasi sampai tahap memanggil AI**, belum sampai dapat respons
  sukses penuh saat pengujian — direkomendasikan dicoba sekali lagi pakai
  API key AI yang aktif untuk memastikan jalur ini benar-benar utuh.
- Fitur di luar scope ini (Social Agent, CRM/lead agent, competitor
  scraping, publishing otomatis) sengaja tidak dibangun — sesuai keputusan
  awal untuk fokus di "ranking via artikel" saja.

## Ke depan

Roadmap tercatat "tidak ada item baru yang menunggu" per hari ini — semua
gap yang direncanakan sudah selesai. Kalau ada kebutuhan baru (tuning angka
threshold, kategori opportunity tambahan, dst.), itu akan dicatat sebagai
item baru di `docs/ROADMAP.md`, bukan dianggap otomatis lanjut dari sini.
