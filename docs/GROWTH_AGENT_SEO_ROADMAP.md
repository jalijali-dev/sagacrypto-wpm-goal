# Growth Agent SEO & Google Search Console

## Roadmap ringkas untuk CMS artikel yang sudah berjalan

Dokumen ini mengambil bagian yang relevan dari roadmap Val's Cake dan menyederhanakannya untuk proyek yang:

- sudah memiliki CMS, special page, page, dan artikel;
- sudah memiliki AI Content Agent untuk membuat artikel;
- tidak membutuhkan Social Agent, CRM Agent, atau automation marketing;
- ingin berfokus pada crawlability, indexing, peluang ranking, optimasi artikel, internal link, dan pengukuran melalui Google Search Console (GSC).

> Target yang realistis bukan “menjamin cepat ranking”, melainkan mempercepat discovery dan evaluasi artikel, memilih peluang SEO berdasarkan data, melakukan perbaikan yang terkontrol, lalu mengukur hasilnya.

---

## 1. Scope yang perlu diambil

### Wajib untuk MVP

1. **Technical SEO foundation**
   - URL artikel permanen dan bersih.
   - canonical URL yang benar.
   - sitemap XML otomatis berisi hanya URL canonical yang layak diindeks.
   - `robots.txt`, meta robots, status HTTP, dan redirect yang benar.
   - meta title, meta description, Open Graph.
   - `Article` JSON-LD; `FAQPage` hanya ketika FAQ memang tampil di halaman.
   - halaman pencarian/filter/tag tipis menggunakan canonical atau `noindex` yang tepat.

2. **Integrasi GSC read-only**
   - OAuth atau service account dengan akses minimum.
   - pengambilan Search Analytics berdasarkan query, page, dan date.
   - penyimpanan snapshot agar periode sekarang dapat dibandingkan dengan periode sebelumnya.
   - URL Inspection API untuk membaca status indeks URL prioritas.
   - status fetch, error, dan waktu sinkronisasi terakhir terlihat di admin.

3. **Opportunity Engine deterministik**
   - menghitung peluang dari data GSC tanpa LLM.
   - scoring, deduplikasi, dan guardrail dilakukan oleh kode.
   - menghasilkan daftar prioritas beserta data pendukungnya.

4. **Growth Agent**
   - membaca data CMS, GSC, dan hasil Opportunity Engine.
   - menjelaskan peluang dan merekomendasikan tindakan.
   - tidak mengarang volume, posisi, trafik, atau kompetitor.
   - tidak menerbitkan dan tidak mengubah artikel secara langsung.

5. **Action Queue dan approval**
   - rekomendasi disimpan sebagai action berstatus `pending`.
   - operator dapat Approve, Ignore, atau Close as duplicate/legacy.
   - approval tidak sama dengan execution.
   - semua perubahan memiliki target, alasan, evidence, status, dan timeline.

6. **Eksekusi melalui agent yang sudah ada**
   - artikel baru diteruskan ke Content Agent sebagai **draft**.
   - optimasi meta/content dibuat sebagai proposal atau draft revisi.
   - operator tetap melakukan review dan publish.

7. **Feedback loop**
   - snapshot GSC sebelum dan sesudah perubahan.
   - evaluasi minimum per URL/query, bukan hanya total seluruh situs.
   - hasil dipakai sebagai context rekomendasi berikutnya, bukan sebagai pemicu eksekusi otomatis.

### Setelah MVP stabil

- internal-link intelligence;
- topic cluster dan content-gap analysis;
- cannibalization detection;
- content freshness;
- CTR/title optimization;
- index coverage dashboard yang lebih lengkap;
- conversion signal seperti WhatsApp click/contact submit bila relevan.

### Tidak diperlukan

- Social Agent, Social Copy, Storyboard, image/video generation;
- Instagram/Meta publishing;
- CRM Agent dan lead follow-up;
- Content Calendar untuk sosial media;
- competitor scraping;
- embeddings/vector database untuk jumlah artikel kecil;
- autonomous publishing atau perubahan langsung oleh AI.

---

## 2. Arsitektur yang disarankan

```text
CMS Articles + Sitemap + GSC
            ↓
      Data Collectors
            ↓
 Snapshot Store + URL Index Status
            ↓
 Deterministic Opportunity Engine
            ↓
        Growth Agent
            ↓
 Agent Action Queue (pending)
            ↓
 Operator Review & Approval
            ↓
 Content Agent / SEO Proposal
            ↓
 Draft or Reviewed Change
            ↓
 Operator Publish
            ↓
 GSC Before/After Measurement
```

Prinsip utama:

- CMS/database adalah system of record.
- data GSC adalah evidence, bukan instruksi.
- LLM menyusun analisis dan rekomendasi; LLM tidak menghitung kebenaran metrik.
- tidak ada jalur langsung dari GSC menuju publish atau edit artikel.
- semua komponen gagal secara aman: jika target/evidence tidak valid, action tidak boleh dieksekusi.

---

## 3. Tahapan implementasi

## Phase 0 — Audit kesiapan SEO

### Pekerjaan

- audit template artikel, canonical, robots, sitemap, redirect, status HTTP, schema, dan internal navigation;
- pastikan draft, preview, pencarian internal, filter kosong, dan URL parameter tidak ikut sitemap;
- pastikan `updated_at` artikel memperbarui `lastmod` sitemap hanya ketika konten benar-benar berubah;
- mapping satu URL canonical ke satu artikel;
- siapkan property GSC dan submit sitemap.

### Definition of Done

- seluruh artikel published memiliki URL canonical unik dan HTTP 200;
- sitemap valid dan hanya berisi URL yang ingin diindeks;
- draft/preview tidak dapat diindeks;
- Article schema tervalidasi;
- GSC property terverifikasi.

---

## Phase 1 — GSC Data Layer

### Data yang diambil

1. **Site summary**
   - clicks;
   - impressions;
   - CTR;
   - average position.

2. **Query intelligence**
   - query;
   - clicks;
   - impressions;
   - CTR;
   - position.

3. **Page intelligence**
   - canonical page URL;
   - clicks;
   - impressions;
   - CTR;
   - position.

4. **Query × page mapping**
   - untuk mengetahui query mana menuju halaman mana;
   - menjadi dasar deteksi cannibalization dan target-page mismatch.

5. **Index inspection untuk URL prioritas**
   - verdict/status indeks;
   - last crawl;
   - robots state;
   - page fetch state;
   - user-declared dan Google-selected canonical jika tersedia;
   - sitemap/referring URL bila tersedia.

### Window data

- periode utama: 28 hari terakhir yang sudah stabil;
- pembanding: 28 hari sebelumnya;
- simpan snapshot historis per tanggal fetch;
- tampilkan timezone dan rentang tanggal secara eksplisit;
- jangan menyimpulkan tren jika data pembanding belum cukup.

### Penyimpanan minimum

Nama tabel dapat disesuaikan dengan proyek.

| Data | Field minimum |
|---|---|
| `gsc_sync_runs` | id, property, start_date, end_date, status, fetched_at, error_summary |
| `gsc_query_page_metrics` | run_id, date/query/page, clicks, impressions, ctr, position |
| `gsc_url_inspections` | page_id/url, verdict, coverage_state, last_crawl, canonical fields, inspected_at |

Untuk MVP kecil, payload snapshot JSON yang bounded juga dapat dipakai. Pilih tabel terstruktur jika filtering, history, dan agregasi akan sering digunakan.

### Operasional

- manual **Fetch GSC Data** terlebih dahulu;
- setelah stabil, jadwalkan harian atau mingguan;
- credential disimpan terenkripsi dan tidak masuk log;
- logging hanya status, durasi, property, jumlah row, dan error aman.

---

## Phase 2 — Opportunity Engine

Opportunity Engine harus berupa kode deterministik. LLM baru menerima hasil akhirnya.

### Kategori peluang MVP

| Kategori | Contoh rule awal | Rekomendasi |
|---|---|---|
| High impressions, low CTR | impressions memadai, posisi 1–10, CTR di bawah baseline situs/query sejenis | perbaiki title/meta dan intent match |
| Near page one | posisi rata-rata sekitar 11–20 | perkuat isi, internal link, dan topical coverage |
| Page-one quick win | posisi 4–10 dengan impressions tetapi click rendah | optimasi snippet dan relevansi jawaban |
| Zero-click opportunity | impressions > 0, clicks = 0 | audit intent, title, dan kualitas halaman |
| Content gap | query relevan memiliki impressions tetapi tidak ada halaman target yang kuat | buat supporting article draft |
| Cannibalization candidate | satu query memiliki dua atau lebih page dengan share signifikan | bedakan intent, konsolidasi, atau pilih pillar |
| Indexing issue | published + canonical + sitemap, tetapi URL tidak terindeks/bermasalah | technical review; jangan otomatis membuat ulang artikel |
| Content decay | clicks/impressions turun versus periode pembanding | review/update artikel existing |

### Scoring

Gunakan skor 0–100 dari komponen yang dapat diaudit:

- demand: impressions;
- ranking gap: jarak ke posisi sasaran;
- CTR gap;
- business relevance;
- content/index status;
- confidence/data sufficiency;
- effort;
- duplication/cannibalization risk.

Output minimum:

```json
{
  "opportunity_type": "near_page_one",
  "target_page_id": 123,
  "target_url": "https://example.com/artikel/contoh",
  "query": "contoh keyword",
  "evidence": {
    "clicks": 4,
    "impressions": 210,
    "ctr": 0.019,
    "position": 12.4,
    "period": "YYYY-MM-DD..YYYY-MM-DD"
  },
  "priority": "high",
  "impact_score": 8,
  "effort_score": 4,
  "recommended_action": "optimize_existing_article"
}
```

### Guardrail

- minimum data threshold dapat dikonfigurasi;
- brand query dapat dipisahkan dari non-brand;
- query sensitif/irrelevan dibuang;
- URL harus cocok dengan page published di CMS;
- opportunity yang sama harus dideduplikasi;
- artikel yang baru diubah masuk cooldown agar tidak direkomendasikan berulang;
- data kurang harus berstatus `insufficient_data`, bukan dipaksakan menjadi opportunity.

---

## Phase 3 — Growth Agent MVP

### Tugas agent

- meringkas kondisi SEO periode berjalan;
- memilih opportunity teratas dari engine;
- menjelaskan “kenapa sekarang” berdasarkan evidence;
- merekomendasikan satu action type yang valid;
- menyebutkan risiko dan expected outcome;
- tidak memberi klaim ranking pasti.

### Input context

- inventory artikel: id, title, slug, category, status, published/updated date;
- ringkasan isi dan SEO metadata;
- hasil Opportunity Engine;
- status index URL;
- internal links bila sudah tersedia;
- action/memory sebelumnya agar rekomendasi tidak berulang;
- daftar action type yang diizinkan.

### Output terstruktur

```json
{
  "summary": "Ringkasan singkat berbasis data",
  "priority_actions": [
    {
      "action_type": "optimize_existing_article",
      "target": {
        "type": "article",
        "id": 123,
        "slug": "contoh-artikel"
      },
      "primary_query": "contoh keyword",
      "reason": "Alasan yang mengacu ke evidence",
      "evidence_refs": ["opportunity:abc123"],
      "suggested_changes": [
        "Perjelas intent pada title",
        "Tambahkan section yang menjawab subtopik"
      ],
      "priority": "high",
      "confidence": "medium",
      "recommended_agent": "content"
    }
  ]
}
```

### Action type MVP

- `create_article_draft`;
- `optimize_existing_article`;
- `optimize_title_meta`;
- `add_internal_links`;
- `review_indexing_issue`;
- `manual_technical_review`.

Jika target tidak bisa di-resolve secara pasti, turunkan menjadi `manual_technical_review`; jangan menebak slug atau page ID.

---

## Phase 4 — Operator-Controlled Action Loop

### Status

```text
pending → approved → executing → done
        ↘ ignored
        ↘ closed_duplicate
        ↘ failed → retry
```

### Separation of responsibility

- **Growth Agent:** merekomendasikan.
- **Opportunity Engine:** menghitung dan memprioritaskan evidence.
- **Content Agent:** membuat artikel baru sebagai draft.
- **SEO/content optimizer:** membuat proposal revisi artikel existing.
- **Operator:** approve, review, publish, dan memutuskan perubahan berisiko.

### Action record minimum

- action type;
- recommended agent;
- target article/page;
- payload terstruktur;
- evidence snapshot;
- priority/impact/effort;
- status dan execution status;
- timestamps;
- before/after result;
- event timeline;
- source memory/opportunity ID untuk deduplikasi.

### Safety

- Approve tidak langsung menjalankan.
- Execute adalah tombol/aksi terpisah.
- artikel baru selalu `draft`.
- perubahan existing content harus mempunyai before/after snapshot.
- perubahan write harus transactional dan dapat rollback bila nanti dibuat applier.
- publish selalu manual pada MVP.

---

## Phase 5 — Indexing Workflow

### Yang dapat diotomatisasi

- generate/update sitemap saat artikel published, updated, unpublished, atau slug berubah;
- validasi bahwa URL published ada di sitemap;
- membaca status indeks melalui URL Inspection API;
- membuat action `review_indexing_issue`;
- memberi checklist penyebab: robots, noindex, canonical, redirect, soft 404, orphan page, thin/duplicate content;
- menyimpan hasil inspeksi dan tanggal pengecekan.

### Yang tetap manual

- **Request Indexing** melalui URL Inspection di Search Console untuk sejumlah kecil URL prioritas;
- keputusan memperbaiki canonical, redirect, atau konten;
- publish artikel.

### Batas penting

Jangan menggunakan Google Indexing API untuk artikel biasa. API tersebut dibatasi untuk halaman `JobPosting` atau livestream dengan `BroadcastEvent` dalam `VideoObject`. Untuk artikel biasa, gunakan sitemap, internal link yang crawlable, URL Inspection, dan perbaikan kualitas/teknis.

Request crawl juga tidak menjamin URL langsung terindeks atau ranking naik.

---

## Phase 6 — Feedback and Measurement

### Baseline

Saat sebuah action diterapkan, simpan:

- action ID dan target page;
- tanggal publish/apply;
- query utama;
- snapshot GSC terdekat sebelum perubahan;
- jenis perubahan;
- before/after metadata atau content hash.

### Measurement window

- hindari evaluasi terlalu cepat;
- bandingkan window yang setara;
- ukur per page/query;
- tandai `insufficient_data` bila tidak ada evidence yang cukup;
- simpan raw deltas, jangan langsung menyebut perubahan sebagai “sukses” hanya karena satu metrik naik.

### Metrik

- indexed/not indexed;
- clicks;
- impressions;
- CTR;
- average position;
- query coverage;
- page/query mapping;
- optional business conversion.

### Growth memory

Simpan kandidat pola hanya setelah memiliki beberapa outcome yang sejenis. Memory hanya menjadi advisory context bagi Growth Agent; memory tidak boleh membuat, approve, atau execute action sendiri.

---

## 4. Modul AI yang benar-benar diperlukan

### 1. Growth Agent

**Perlu AI:** ya.

Fungsi: menyintesis evidence, memilih narasi rekomendasi, memberi alasan dan risiko.

### 2. Content Agent existing

**Perlu AI:** sudah tersedia.

Penyesuaian: menerima context tambahan berupa primary query, search intent, pillar page, supporting links, content gap, dan larangan cannibalization. Output tetap draft.

### 3. Existing Article Optimizer

**Perlu AI:** disarankan setelah MVP.

Fungsi: membuat proposal title/meta/outline/FAQ/section improvement untuk artikel existing. Jangan langsung menulis ke production.

### 4. Internal Link Recommender

**AI opsional.**

Candidate generation dan validation sebaiknya deterministik. AI boleh membantu menyarankan anchor/context, tetapi source dan target harus berasal dari CMS yang sudah divalidasi.

### Tidak memakai AI

- pembacaan GSC;
- perhitungan CTR/position/delta;
- opportunity scoring;
- deduplikasi;
- URL/index validation;
- status workflow;
- authorization;
- audit log;
- publish;
- rollback.

---

## 5. Dashboard minimum

### Growth Overview

- last GSC sync;
- total clicks, impressions, CTR, position;
- indexed/not indexed untuk URL yang diperiksa;
- jumlah opportunity High/Medium/Low;
- jumlah action Pending/Approved/Done/Failed.

### Opportunity Queue

Filter:

- category;
- priority;
- target page;
- action type;
- status;
- date.

Setiap card/row menampilkan:

- target;
- query;
- evidence;
- alasan;
- impact/effort;
- recommended action;
- View, Approve, Ignore.

### Article Intelligence

- performa per artikel;
- query utama;
- trend versus periode sebelumnya;
- index status;
- rekomendasi aktif;
- last action dan outcome.

### Manual controls

- Fetch GSC Data;
- Run Growth Analysis;
- Inspect URL;
- Generate Draft setelah action disetujui;
- Execute/Retry hanya untuk executor yang aman.

---

## 6. Urutan pengerjaan yang paling efisien

### Sprint 1 — Foundation

- technical SEO audit;
- sitemap/canonical/schema;
- GSC property dan credential;
- manual GSC fetch;
- snapshot storage.

### Sprint 2 — Intelligence

- query/page/query×page ingestion;
- deterministic opportunity rules;
- priority scoring dan dedup;
- basic dashboard.

### Sprint 3 — Growth Agent

- bounded context builder;
- versioned prompt;
- strict JSON output;
- action creation sebagai pending;
- fallback jika AI/provider gagal.

### Sprint 4 — Controlled execution

- action review UI;
- approve/ignore/close;
- route `create_article_draft` ke Content Agent;
- timeline dan execution result;
- no auto-publish.

### Sprint 5 — Index and feedback

- URL Inspection untuk URL prioritas;
- indexing issue workflow;
- before/after GSC history;
- per-page/query outcome view.

### Sprint 6 — Expansion

- internal links;
- topic cluster;
- cannibalization;
- content refresh;
- conversion context.

---

## 7. Acceptance test utama

1. GSC fetch tidak pernah mengubah artikel atau membuat action secara langsung.
2. Query/page metrics tersimpan dengan periode dan property yang benar.
3. Opportunity Engine memberi hasil identik untuk input identik.
4. Malformed/unknown page tidak menghasilkan executable action.
5. Growth Agent hanya mereferensikan evidence yang diberikan.
6. Semua action baru berstatus `pending`.
7. Approve tidak sama dengan Execute.
8. Content Agent menghasilkan artikel `draft`, bukan published.
9. Sitemap hanya berisi canonical published URLs.
10. Index issue tidak otomatis dianggap sebagai kebutuhan menulis ulang artikel.
11. Action berulang terdeduplikasi dan action yang baru selesai masuk cooldown.
12. Before/after measurement tidak memakai site-wide totals untuk menilai satu URL.
13. Kurangnya data menghasilkan `insufficient_data`.
14. Credential, prompt, raw AI output, dan secret tidak bocor ke log.
15. Provider/GSC failure tidak merusak CMS utama.

---

## 8. Keputusan yang perlu dibuat sebelum coding

- GSC authentication: service account atau OAuth user;
- frekuensi fetch dan retention snapshot;
- ambang impressions/position/CTR untuk opportunity;
- daftar action type final;
- apakah optimasi artikel existing hanya proposal atau mempunyai safe applier;
- cooldown setelah publish/perubahan;
- kapan URL Inspection dijalankan dan berapa URL prioritas per batch operasional;
- model/provider AI, budget per run, timeout, dan rate limit;
- format prompt versioning dan schema validation;
- siapa yang memiliki hak Approve, Execute, dan Publish.

---

## Rekomendasi MVP final

Bangun hanya lima kemampuan berikut lebih dulu:

1. **GSC Collector** — mengambil dan menyimpan search performance.
2. **Opportunity Engine** — menentukan peluang dengan rule deterministik.
3. **Growth Agent** — mengubah evidence menjadi rekomendasi terstruktur.
4. **Action Queue** — review, approve, dan audit.
5. **Content Agent Adapter** — menghasilkan article draft dari action yang sudah disetujui.

Internal link, cannibalization, article optimizer, learning loop, dan conversion tracking masuk iterasi berikutnya setelah data GSC dan workflow MVP terbukti stabil.

