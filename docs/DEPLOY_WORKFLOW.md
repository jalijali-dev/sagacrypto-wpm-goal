# DEPLOY WORKFLOW — Cheat Sheet Harian

Panduan cepat 3 langkah yang dipakai berulang tiap ada perubahan kode yang
mau naik ke production. Baca `HANDOFF.md` dulu kalau butuh konteks penuh
soal topologi project — file ini murni cheat-sheet command, bukan
penjelasan arsitektur.

Terakhir di-update: 28 Juli 2026

## Alur singkat

```
Claude Code (Mac)  →  GitHub (jalijali-dev/sagacrypto-wpm-goal)  →  cPanel repositories/  →  public_html/
     push                                                               pull                    cp selective
   (devs)                                                            (operator)              (operator)
```

Devs tidak pernah pegang akses cPanel. Operator (Donnie) yang jadi gerbang
terakhir — jalanin pull + cp manual, sekaligus jadi checkpoint review
sebelum kode nyampe production.

## ⚠️ Topologi penting — WAJIB dipahami sebelum `cp`

Berbeda dari asumsi awal project (split subdomain terpisah sejajar
`public_html`), topologi sebenarnya di server ini (path home dir, nama
repo, dan domain dikonfirmasi ulang 28 Jul 2026):

```
/home/sagagoal/
  ├── repositories/sagacrypto-wpm-goal/   ← working copy git, di LUAR public_html, aman
  └── public_html/                         ← docroot utama (sagagoal.com)
       ├── index.php, artikel.php, dst   ← FRONTEND, cp langsung ke sini
       └── cms-admin/                     ← docroot ADMIN (wpm.sagagoal.com)
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

### ⚠️ Troubleshooting — `.git/index.lock` nyangkut terus

**Gejala:** `git add`/`git commit` gagal dengan `fatal: Unable to create
'.../.git/index.lock': File exists`, dan `rm -f .git/index.lock` kelihatan
sukses tapi lock-nya muncul lagi di command berikutnya.

**Penyebab (kejadian nyata 28 Jul 2026):** folder project ini di-*share* ke
VM Docker Desktop lewat VirtioFS — mekanisme delete-file di situ beda dari
filesystem Mac biasa, jadi delete dari sisi Mac kadang belum "nempel" ke
sisi VM. Cek dulu siapa yang pegang file-nya sebelum ambil tindakan:

```bash
ps aux | grep -i "[g]it"
lsof ".git/index.lock" 2>/dev/null
```

Kalau yang muncul adalah proses `/System/Library/Frameworks/
Virtualization.framework/...` — itu VM engine Docker Desktop sendiri.
**Jangan di-`kill`** (itu bakal matiin seluruh Docker Desktop, termasuk
container MySQL yang lagi jalan). Cukup jalanin delete + operasi git
sebagai **satu baris utuh tanpa jeda** (jeda waktu nunggu balesan chat/
proses lain itu yang bikin lock-nya nyangkut lagi):

```bash
rm -f .git/index.lock && git add -A && git commit -m "..." && git push origin main
```

Kalau masih gagal juga setelah itu, restart Docker Desktop dulu (via menu
bar icon → Restart, bukan `kill -9` paksa) baru ulangi command di atas.

### ⚠️ Troubleshooting — push ditolak `non-fast-forward` / history diverged

**Gejala:** `git push` ditolak dengan pesan "Updates were rejected because
the tip of your current branch is behind its remote counterpart", ATAU
`main` lokal dan `origin/main` ternyata dua garis sejarah yang gak nyambung
sama sekali (bukan cuma "ketinggalan beberapa commit").

**Cara diagnosa dulu sebelum ambil tindakan** — jangan langsung `pull` atau
`push --force` tanpa cek ini:

```bash
git fetch origin
git log --oneline main..origin/main   # commit yang ada di origin, gak ada di lokal
git log --oneline origin/main..main   # commit yang ada di lokal, gak ada di origin
```

Kalau dua-duanya sama-sama nunjukin commit dalam jumlah besar (bukan cuma
1-2 commit ketinggalan) — itu tandanya dua history beneran gak nyambung
(unrelated histories), bukan sekadar "kurang up-to-date". Kejadian nyata
28 Jul 2026: `main` lokal sempat direset ulang (2 commit) sementara
`origin/main` masih nyimpen 22 commit history asli project (integrasi GSC
versi lama, RBAC, dll) — dua-duanya gak share ancestor sama sekali.

**Kalau situasinya emang "mau ganti total, versi lokal yang menang"** (opsi
ini SELALU harus dikonfirmasi eksplisit ke user dulu, jangan pernah
diasumsikan) — force push aman dipakai:

```bash
git push origin main --force
```

Efeknya: history lama di `origin/main` gak lagi kelihatan di branch `main`
(walau belum tentu langsung kehapus permanen di server GitHub — anggap aja
hilang dari pandangan normal). Kalau ada keraguan sedikit pun soal history
lama itu masih dibutuhkan, **jangan** langsung force push — diskusikan dulu
opsi merge yang mempertahankan history lama sebagai ancestor
(`git merge -s ours --allow-unrelated-histories origin/main` dari branch
lokal, lalu push biasa) sebelum memutuskan.

## 2️⃣ Pull ke cPanel (dikerjakan operator, via Terminal cPanel)

```bash
cd ~/repositories/sagacrypto-wpm-goal
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

**Alternatif — cPanel "Git™ Version Control" UI** (Manage Repository → tab
"Pull or Deploy") juga bisa dipakai sebagai pengganti `git pull` manual di
atas: tombol **"Update from Remote"** buat fetch commit terbaru dari
GitHub, dan tombol **"Deploy HEAD Commit"** buat langsung jalanin script
`.cpanel.yml` (lihat catatan soal itu di bawah). Sama-sama valid, pilih
yang lebih nyaman.

### ⚠️ Troubleshooting — "No checked-out branch is available" / "Update from Remote" error `"" is not a valid "branch"`

**Kejadian nyata 28 Jul 2026:** setelah force-push (lihat troubleshooting
step 1), clone repo di cPanel jadi kehilangan referensi branch yang valid
sama sekali — tab "Basic Information" nunjukin "No checked-out branch is
available", dan klik "Update from Remote" di tab "Pull or Deploy" malah
error `Error: "" is not a valid "branch"`. Ini gak bisa dibenerin cuma
lewat "Update from Remote" doang.

**Cara benerinnya** — hapus & clone ulang repo cPanel-nya (aman, ini cuma
clone buat staging deploy, bukan sumber commit unik, GitHub sama sekali
gak kesentuh):

1. cPanel → Git™ Version Control → **List Repositories** → klik
   **"Remove"** di repo yang bermasalah (cuma hapus folder clone lokal +
   referensi cPanel, GitHub aman).
2. Klik **"Create"** → pilih **"Clone a Repository"** → isi Clone URL
   (`https://github.com/jalijali-dev/sagacrypto-wpm-goal.git`) dan
   Repository Path yang sama seperti sebelumnya
   (`/home/sagagoal/repositories/sagacrypto-wpm-goal`).
3. Verifikasi di tab "Basic Information": "Currently Checked-Out Branch"
   harus nunjuk ke `main`, dan HEAD Commit harus sama persis dengan commit
   terbaru yang barusan di-push dari Mac.

### 📄 Isi `.cpanel.yml` — apa yang beneran dijalanin tombol "Deploy HEAD Commit"

```yaml
deployment:
  tasks:
    - export DEPLOYPATH=/home/sagagoal/public_html/
    - /bin/cp -R * $DEPLOYPATH
    - /bin/cp -R .htaccess $DEPLOYPATH 2>/dev/null || true
```

Ini `cp -R *` **seluruh isi repo** ke `public_html/` — kelihatannya
bertentangan sama aturan "jangan pernah `cp -r` semua folder" di § 3 di
bawah, tapi **sebenarnya aman** karena semua file sensitif (kredensial DB
di `cms-admin/config/database.php`, folder `uploads/`) sengaja gak pernah
ikut ke-track di git (lihat `.gitignore`) — jadi mereka gak akan pernah ada
di source yang di-`cp`, dan file asli di `public_html` yang isinya
kredensial/upload asli gak akan ketimpa. Selama `.gitignore` tetap konsisten
melindungi file-file itu, klik "Deploy HEAD Commit" adalah cara **tercepat**
buat sinkronin `public_html` — gak perlu manual `cp` satu-satu seperti § 3
di bawah. Pakai § 3 (manual selektif) hanya kalau mau review dulu file mana
aja yang berubah sebelum nimpa production, atau kalau ragu ada drift di
`public_html` yang gak tercermin di git.

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
