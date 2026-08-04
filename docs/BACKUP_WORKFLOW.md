# BACKUP WORKFLOW — Database + File ke Google Drive

Cheat sheet backup otomatis mingguan yang jalan di server (cPanel), bukan
dari sandbox Claude — sandbox tidak punya akses DB/file live (lihat
`docs/DEV_GUIDE.md` § 6). Setup ini dikerjakan & diverifikasi end-to-end
31 Jul 2026.

## Ringkasan

```
Cron cPanel (tiap Minggu 02:00)
        ↓
~/backup-weekly.sh (jalan di server, home dir /home/sagagoal)
   ├── mysqldump database sagagoal_cms → gzip
   ├── tar seluruh ~/public_html (termasuk cms-admin & uploads) → gzip
   ├── rclone copy kedua file ke Google Drive (remote "gdrive")
   └── hapus backup lokal di ~/backups yang lebih tua dari 14 hari
```

- Tujuan Drive: folder **`SagagoalBackups`** di root "Drive Saya" akun
  Google yang dipakai connect (`ragaraja2201@gmail.com`).
- Backup lokal sementara disimpan di `~/backups/` (di server, **di luar**
  `public_html` — tidak web-accessible).
- Ukuran per backup saat setup: ~157K (DB) + ~5.6M (files) — total situs
  masih kecil (`public_html` ~7.4M).

## Komponen yang terpasang di server

- **rclone** (`~/bin/rclone`, v1.74.4) — binary standalone, tidak butuh
  root, di-download manual dari `downloads.rclone.org` (bukan lewat
  package manager, karena akses cPanel shared hosting tidak punya `sudo`).
- Remote rclone bernama **`WPM-sagagoal`** (sebelumnya sempat bernama
  `gdrive`, di-rename total — bukan cuma ganti nama, tapi hapus+bikin
  ulang remote — saat pindah akun Google tujuan backup, lihat § "Riwayat
  ganti akun Drive" di bawah), scope OAuth **`drive.file`** (cuma akses
  file yang dibuat rclone sendiri — sengaja dipilih scope paling sempit,
  bukan full Drive access).
- Config token rclone tersimpan di `~/.config/rclone/rclone.conf` di
  server (bukan di repo, bukan di git — murni lokal server).
- Script backup: `~/backup-weekly.sh` (home dir server, **bukan** bagian
  dari repo `sagacrypto-wpm-goal` — murni file operasional di server).
- Cron job cPanel: `0 2 * * 0` (Minggu jam 2 pagi) →
  `/bin/bash /home/sagagoal/backup-weekly.sh >> /home/sagagoal/backups/backup.log 2>&1`

## Isi `~/backup-weekly.sh` (referensi)

```bash
#!/bin/bash
set -e
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p ~/backups

cat > ~/.my.cnf.tmp <<'EOF2'
[client]
user=sagagoal_admin
password="<lihat cms-admin/config/database.php di server>"
host=localhost
EOF2
chmod 600 ~/.my.cnf.tmp

mysqldump --defaults-extra-file=~/.my.cnf.tmp sagagoal_cms | gzip > ~/backups/sagagoal_db_$DATE.sql.gz
tar -czf ~/backups/sagagoal_files_$DATE.tar.gz -C ~/public_html .
rm -f ~/.my.cnf.tmp

~/bin/rclone copy ~/backups/sagagoal_db_$DATE.sql.gz WPM-sagagoal:SagagoalBackups
~/bin/rclone copy ~/backups/sagagoal_files_$DATE.tar.gz WPM-sagagoal:SagagoalBackups

find ~/backups -type f -mtime +14 -delete
echo "Backup selesai: $DATE"
```

**Password DB sengaja tidak ditulis plaintext di dokumen ini** (dokumen
ini ikut ke-commit ke git) — cek langsung `cms-admin/config/database.php`
di server kalau perlu reproduce script ini dari nol.

## Troubleshooting yang sudah ketemu (31 Jul 2026)

- **Password DB mengandung `#`** (`LIVESCORE!@#`) → di file `.my.cnf`
  (format MySQL option file), `#` di mana pun di satu baris dianggap awal
  komentar, bukan cuma di awal baris. Fix: wajib bungkus value password
  dengan tanda kutip di file cnf-nya: `password="LIVESCORE!@#"`. Tanpa
  quote, password kepotong jadi `LIVESCORE!@` → `Access denied`.
- **Password mengandung `!`** → kalau ditulis langsung di command line
  interaktif bash (bukan lewat file), `!` bisa kena history expansion
  bash dan corrupt. Solusinya sama: selalu lewat file `.my.cnf` yang
  di-`chmod 600`, dihapus lagi setelah dipakai — jangan pernah taruh
  password mentah di argumen command line `mysqldump -p...`.
- **`mysqldump: Access denied ... PROCESS privilege ... tablespaces`** —
  ini cuma warning, bukan kegagalan. User DB shared-hosting biasa memang
  tidak punya privilege `PROCESS` buat baca metadata tablespace level
  server, tapi dump tabel & datanya sendiri tetap jalan penuh. Sudah
  diverifikasi: isi dump tetap lengkap (31 tabel, ada `-- Dump completed
  on ...` di akhir file).
- **Shared client_id rclone bakal di-retire pertengahan 2026** — notice
  muncul tiap rclone jalan (`This remote uses rclone's shared Google
  Drive client_id, which is being retired...`). Belum jadi masalah saat
  setup ini (31 Jul 2026), tapi kalau nanti cron mulai gagal karena ini,
  perbaikannya: bikin OAuth client_id sendiri di Google Cloud Console
  (lihat https://rclone.org/drive/#making-your-own-client-id), lalu
  update remote `gdrive` (`rclone config` → edit remote → isi
  `client_id`/`client_secret` sendiri, config token perlu di-generate
  ulang).

## Cara verifikasi manual kapan aja

```bash
# Cek riwayat run cron
cat ~/backups/backup.log

# Cek file lokal yang masih ada (belum lewat 14 hari)
ls -lh ~/backups

# Cek isi folder Drive langsung dari server
~/bin/rclone lsl WPM-sagagoal:SagagoalBackups

# Jalanin manual di luar jadwal cron kalau perlu backup dadakan
bash ~/backup-weekly.sh
```

## Riwayat ganti akun Drive tujuan (31 Jul 2026)

Tujuan backup dipindah dari akun Google `ragaraja2201@gmail.com` (remote
lama `gdrive`) ke akun Google baru (remote baru **`WPM-sagagoal`**).

- **2 backup pertama (31 Jul dini hari, sebelum pindah) sengaja
  dibiarkan** di Drive akun lama, folder `SagagoalBackups` — tidak
  di-migrasi/copy ke akun baru (keputusan eksplisit, bukan kelupaan).
  Kalau perlu file itu lagi, harus dicari manual di akun lama.
- Rename remote **bukan** sekadar `rclone config rename` — remote lama
  `gdrive` dihapus total dan dibuat ulang dengan nama `WPM-sagagoal`,
  supaya re-autentikasi OAuth beneran jalan (ketemu bug: memilih "Edit
  existing remote" lalu jawab "No" di prompt "Already have a token -
  refresh?" **tidak** memicu browser-auth baru — rclone diam-diam
  menyimpan ulang token lama tanpa re-auth. Fix: `d` untuk delete remote,
  baru `n` untuk New remote dari nol — ini yang benar-benar memicu alur
  `rclone authorize` baru).
- Setelah remote baru dibuat, `~/backup-weekly.sh` di-update (`sed`)
  supaya kedua baris `rclone copy` menunjuk ke `WPM-sagagoal:...`,
  bukan `gdrive:...` lagi. Cron job (`0 2 * * 0`) tidak perlu diubah —
  tetap manggil script yang sama, cuma isinya yang berubah.
- Diverifikasi end-to-end: `bash ~/backup-weekly.sh` dijalankan manual
  setelah perubahan, kedua file baru muncul di folder `SagagoalBackups`
  akun Google yang baru.

## Cara restore (belum pernah dites end-to-end — catat di sini kalau nanti dites)

- File `.sql.gz` → `gunzip` lalu `mysql -u <user> -p <db_name> < file.sql`.
- File `.tar.gz` → `tar -xzf file.tar.gz -C <tujuan>` (isinya persis isi
  `public_html` saat backup diambil, termasuk `cms-admin/`).
- **Belum pernah dicoba restore sungguhan** — kalau suatu saat dites,
  update bagian ini dengan hasil & langkah persisnya, jangan asumsikan
  proses restore mulus tanpa pernah diverifikasi.
