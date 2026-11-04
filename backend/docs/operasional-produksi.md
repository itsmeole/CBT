# Operasional Backend CBT

## Antrean Redis

Jalankan migration pada environment yang dituju dan set rahasia melalui environment manager (jangan simpan kredensial dalam repository):

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=180
```

Jalankan scheduler setiap menit dan worker permanen di bawah Process Supervisor. `--timeout` harus lebih pendek dari `retry_after`.

```sh
php artisan schedule:work
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=120 --max-time=3600
php artisan queue:work redis --queue=reports --sleep=1 --tries=3 --timeout=120 --max-time=3600
php artisan queue:failed
```

Gunakan worker terpisah untuk queue `default` (penilaian/event) dan `reports` (PDF), agar render dokumen tidak menahan event ujian. Pantau `queue:failed`, memori worker, panjang antrean, Redis, database, serta koneksi Reverb. Setelah deploy, jalankan `php artisan queue:restart` agar worker memakai kode baru.

## Backup database

Perintah `php artisan app:backup-database` membuat dump konsisten MySQL/MariaDB dengan `mysqldump --single-transaction`. Scheduler menjalankannya setiap hari pukul 02:00 WIB. Konfigurasi:

```env
BACKUP_DISK=local
BACKUP_PATH=backups/database
DB_DUMP_PATH=mysqldump
DB_DUMP_TIMEOUT=3600
```

Pastikan binary dump tersedia bagi user service, lokasi backup privat memiliki kapasitas memadai, dan salinan dipindahkan ke storage terpisah terenkripsi. Atur retensi/rotation dan pemantauan keberhasilan di lingkungan deployment. Lakukan restore drill ke database terisolasi; jangan pernah menguji restore dengan menimpa database produksi.

## Load test 700 siswa

Script `tests/load/fase3.js` membutuhkan 700 akun siswa unik yang sudah terdaftar pada satu sesi ujian, sesi telah dijadwalkan, dan operator telah memulai ujian saat script menunggu status `mengerjakan`. Gunakan jadwal khusus uji dengan durasi singkat agar seluruh peserta dapat submit dekat deadline server yang sama. Script menjalankan 700 VU, satu iterasi per akun. Siapkan CSV privat berisi header `username,password` dan satu baris per siswa; jangan menggunakan akun atau data produksi.

Contoh PowerShell:

```powershell
k6 run -e BASE_URL=https://test.example.edu `
  -e USERS_CSV=tests/load/users.csv `
  -e EXAM_TOKEN=TOKEN_UJI `
  -e OPERATOR_USERNAME=operator-uji `
  -e OPERATOR_PASSWORD=$env:CBT_TEST_OPERATOR_PASSWORD `
  -e SUBMIT_LEAD_SECONDS=5 `
  tests/load/fase3.js
```

Gunakan hanya environment uji yang memiliki kapasitas dan data uji khusus. Jangan jalankan ke produksi. Script melakukan 700 login dan join serentak, mengambil state/lembar soal, autosave dengan jeda 5–15 detik, mengirim lock/unlock acak sekitar 15%, lalu submit berdekatan pada deadline server. Threshold p95 autosave/lock adalah 300 ms, login/join/state 500 ms, dan error request di bawah 1%. Hasil tetap harus disimpan dan ditinjau sebelum menyatakan kapasitas terpenuhi.

## Recovery drill

1. Ambil backup dan verifikasi dump dapat dipulihkan ke database staging terisolasi.
2. Mulai sesi uji, simpan beberapa jawaban, lalu hentikan worker/scheduler secara terkendali.
3. Restart proses aplikasi, worker queue, scheduler, dan Reverb satu per satu.
4. Verifikasi jawaban dan deadline tetap tersimpan, siswa dapat melanjutkan via state API, antrean yang tertunda diproses, serta auto-submit tetap terjadi pada deadline.
5. Catat waktu pemulihan, job gagal, dan selisih data. Ulangi setelah perubahan konfigurasi atau infrastruktur.

Health endpoint Laravel `/up` hanya memeriksa ketersediaan aplikasi. Monitoring produksi juga harus memeriksa konektivitas database/Redis, antrean job, Reverb, kapasitas storage, dan keberhasilan backup.
