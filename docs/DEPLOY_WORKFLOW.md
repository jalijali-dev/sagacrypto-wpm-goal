# DEPLOY WORKFLOW — Cheat Sheet Harian

Panduan cepat 3 langkah yang dipakai berulang tiap ada perubahan kode yang
mau naik ke production. Baca `HANDOFF.md` dulu kalau butuh konteks penuh
soal topologi project — file ini murni cheat-sheet command, bukan
penjelasan arsitektur.

Terakhir di-update: 18 Juli 2026

## Alur singkat

```
Claude Code (Mac)  →  GitHub (jalijali-dev/sagacrypto-wpm)  →  cPanel repositories/  →  public_html/
     push                                                          pull                    cp selective
   (devs)                                                       (operator)              (operator)
```

Devs tidak pernah pegang akses cPanel. Operator (Donnie) yang jadi gerbang
terakhir — jalanin pull + cp manual, sekaligus jadi checkpoint review
sebelum kode nyampe production.

## ⚠️ Topologi penting — WAJIB dipahami sebelum `cp`

Berbeda dari asumsi awal project (split subdomain terpisah sejajar
`public_html`), topologi sebenarnya di server ini:

```
/home/sagacrypto/
  ├── repositories/sagacrypto-wpm/   ← working copy git, di LUAR public_html, aman
  └── public_html/                    ← docroot utama (sagacrypto.com)
       ├── index.php, artikel.php, dst   ← FRONTEND, cp langsung ke sini
       └── cms-admin/                     ← docroot ADMIN (wpm.sagacrypto.com)
            ├── pages/, assets/, includes/, dst
```

Jadi:

- File frontend (root repo: `index.php`, `artikel.php`, `crypto.php`, dll)
  → `cp` ke `~/public_html/`
- File admin (folder `cms-admin/` di repo) → `cp` ke `~/public_html/cms-admin/`
  — prefix `cms-admin/` TETAP ADA, tidak di-flatten hilang seperti dugaan awal.

## 1️⃣ Push ke Git (dikerjakan devs / Claude Code, di Mac)

```bash
git add .
git commit -m "Deskripsi perubahan yang jelas"
git push origin main
```

Kalau ada banyak perubahan tapi cuma sebagian yang mau di-commit:

```bash
git add path/ke/file/spesifik.php
git commit -m "..."
git push origin main
```

## 2️⃣ Pull ke cPanel (dikerjakan operator, via Terminal cPanel)

```bash
cd ~/repositories/sagacrypto-wpm
git pull origin main
```

Verifikasi commit yang masuk sebelum lanjut:

```bash
git log -1
```

Cek detail file apa aja yang berubah di commit itu (opsional tapi
disarankan buat perubahan besar):

```bash
git show --stat <hash-commit>
```

## 3️⃣ `cp` selective ke file asli (dikerjakan operator)

Contoh — update file frontend:

```bash
cp index.php ~/public_html/index.php
```

Contoh — update file admin (CSS/PHP di cms-admin):

```bash
cp cms-admin/assets/css/admin.css ~/public_html/cms-admin/assets/css/admin.css
cp cms-admin/pages/growth-agent.php ~/public_html/cms-admin/pages/growth-agent.php
```

Verifikasi hasil copy identik dengan sumber:

```bash
diff <file-di-repo> ~/public_html/<path-tujuan>
```

Kosong (tidak ada output) = sukses, file identik.

## Tips

- Jangan pernah `cp -r` seluruh folder tanpa pikir panjang — selalu `cp`
  file spesifik yang memang berubah (cek dari `git show --stat` di step 2),
  biar tidak ada file lain yang ketimpa tanpa sadar.
- File yang cuma dipakai git (`.gitignore`, `.git/`, `docs/*.md`,
  `HANDOFF.md`, `SITEMAP.md`) tidak perlu di-`cp` ke `public_html` — itu
  murni buat referensi/dokumentasi, tidak dipakai runtime.
- Kalau ragu nama folder docroot subdomain lain di masa depan, cek dulu:

```bash
ls -la ~/public_html
```

Jangan asumsi nama foldernya sama persis dengan nama subdomain.
