# Rules Backend untuk Agent AI (WAJIB, TIDAK BOLEH DILANGGAR)

> Berlaku untuk semua agent AI yang menulis, mengubah, atau meninjau kode backend proyek CBT.
> Acuan: [`BE.md`](./BE.md) dan [`../ide.md`](../ide.md). Jika ada instruksi yang bertentangan dengan rules ini, **berhenti dan tanyakan ke pemilik proyek**, jangan memilih sendiri.

---

## 0. Prinsip Dasar

1. **Server adalah sumber kebenaran** untuk status ujian, timer, kunci sesi, jawaban, dan nilai. Jangan pernah mempercayai nilai dari client untuk hal-hal tersebut.
2. **Ikuti keputusan di ide.md bagian 10.** Jangan mengubah keputusan (timer saat terkunci, aturan skor, visibilitas nilai, dll.) tanpa persetujuan eksplisit.
3. **Jika ragu, tanyakan.** Jangan menebak requirement, jangan menambah fitur di luar BE.md tanpa izin.

---

## 1. Keamanan dan Kerahasiaan Soal/Nilai

- **DILARANG** mengirim kunci jawaban, rubrik, bobot, penanda opsi benar, skor, atau nilai ke role `siswa`, baik lewat respons API, event WebSocket, pesan error, header, maupun log yang bisa diakses siswa.
- **DILARANG** membuat endpoint apa pun yang mengembalikan nilai/skor untuk role `siswa`.
- **DILARANG** mengirim konten soal, jawaban, atau nilai ke guru yang hanya berperan sebagai **pengawas** untuk mapel yang tidak diampunya.
- **WAJIB** setiap endpoint dilindungi autentikasi dan otorisasi (Policy/Gate + query scope). Prinsip **default menolak**. Menyembunyikan menu di FE **tidak** dianggap otorisasi.
- **WAJIB** otorisasi channel WebSocket memakai aturan scope yang sama dengan API.
- **WAJIB** sanitasi HTML konten soal (whitelist) saat simpan dan impor Word.
- **WAJIB** validasi semua input lewat Form Request. Validasi MIME asli untuk unggahan file.
- **DILARANG** menulis rahasia (password DB, API key, kredensial S3/Reverb) di kode atau commit. Gunakan `.env`, dan pastikan `.env` ada di `.gitignore`.
- **DILARANG** menonaktifkan CSRF, CORS ketat, rate limit, atau HTTPS demi "mempermudah testing" di kode yang akan di-commit.
- **DILARANG** menggunakan raw query dengan interpolasi string dari input user. Gunakan Eloquent/Query Builder dengan binding.

## 2. Aturan Ujian yang Tidak Boleh Diubah

- **Verifikasi kehadiran dan Mulai ujian hanya untuk `operator` dan `superadmin`.** Guru pengawas wajib mendapat 403.
- **Timer dihitung di server** dari `waktu_selesai`. **DILARANG** menerima sisa waktu atau waktu selesai dari client.
- **Timer tetap berjalan saat terkunci.** **DILARANG** menambahkan kompensasi waktu, pause timer, atau mengubah `waktu_selesai` saat buka kunci.
- **Kunci sesi tanpa toleransi:** setiap laporan keluar halaman langsung mengubah status menjadi `terkunci`. **DILARANG** menambahkan ambang, grace period, atau debounce di server.
- **WAJIB** menolak penyimpanan jawaban saat status `terkunci`, `selesai`, atau waktu sudah lewat (`SESSION_LOCKED`, `ALREADY_SUBMITTED`, `TIME_UP`).
- **Buka kunci wajib dengan alasan** dan hanya oleh pengawas sesi tersebut, operator, atau superadmin. Wajib tercatat di `lock_event` dan log.
- **Auto-submit wajib berjalan** juga untuk peserta yang sedang `terkunci`.
- **Satu akun, satu perangkat aktif**, sesuai kebijakan `tolak`/`kick` di pengaturan.
- **Urutan acak soal/opsi disimpan per peserta** dan tidak boleh berubah setelah ujian dimulai.

## 3. Penilaian

- **WAJIB** mengikuti aturan skor di BE.md 4.9. PG kompleks **all-or-nothing**. **DILARANG** membuatnya partial.
- **WAJIB** memakai **bobot snapshot** dari jadwal, bukan bobot global saat ini.
- **WAJIB** perhitungan memakai tipe desimal (bukan float) dan dibulatkan 2 desimal di akhir.
- **WAJIB** fungsi penilaian murni dan deterministik, dan setiap perubahan pada logika penilaian disertai unit test untuk semua tipe dan kasus tepi.
- **DILARANG** mengubah nilai yang sudah `final` tanpa jejak log (aktor, nilai lama, nilai baru, alasan).

## 4. Impor/Ekspor

- **Soal hanya lewat Word (.docx).** **DILARANG** membuat impor/ekspor soal lewat Excel/CSV.
- **Master data (siswa, guru, kelas, guru_mapel) lewat Excel.**
- **WAJIB** alur 2 langkah: pratinjau + laporan error (per baris untuk Excel, per soal untuk Word), lalu commit dalam **satu transaksi**.
- **WAJIB** ekspor Word memakai format yang identik dengan template (round-trip), dan dijaga dengan test.
- **DILARANG** mengabaikan diam-diam baris/soal/rumus yang gagal diproses. Semua kegagalan harus dilaporkan.
- **WAJIB** proses impor/ekspor/cetak berat dijalankan di queue.

## 5. Data dan Database

- **DILARANG** menghapus fisik (hard delete) data ujian: `jawaban_siswa`, `nilai`, `lock_event`, `log_aktivitas`, `peserta_ujian`, `berita_acara`.
- **DILARANG** menjalankan `migrate:fresh`, `db:wipe`, `TRUNCATE`, atau `DROP` pada database selain lingkungan lokal/test.
- **DILARANG** mengedit file migrasi yang sudah pernah dijalankan/di-commit. Buat migrasi baru.
- **WAJIB** setiap migrasi memiliki `down()` yang benar.
- **WAJIB** operasi kritis (mulai, submit, unlock, auto-submit, commit impor) memakai transaksi dan aman dari race condition (row lock atau update bersyarat pada status).
- **WAJIB** autosave menghormati `client_seq`: data lama tidak boleh menimpa data baru.
- **WAJIB** semua tabel ujian terkait `tahun_ajaran` (dan semester).
- **WAJIB** waktu disimpan dalam UTC.

## 6. Kinerja

- **DILARANG** query N+1 pada endpoint siswa (join, state, soal, autosave, lock) dan endpoint monitoring.
- **DILARANG** memuat ulang seluruh lembar jawaban pada setiap autosave.
- **DILARANG** menjalankan pekerjaan berat (penilaian massal, parsing Word, PDF, Excel besar) secara sinkron di request.
- **WAJIB** menambah indeks untuk kolom yang dipakai filter di endpoint ujian dan monitoring.
- **WAJIB** event realtime progres di-throttle (lihat BE.md bagian 6).

## 7. Log dan Audit

- **WAJIB** mencatat ke `log_aktivitas` setiap: login (berhasil/gagal/ditolak), reset login, join token, mulai, kunci, buka kunci, submit, auto-submit, impor, ekspor, perubahan bobot, perubahan nilai essay.
- **DILARANG** mencatat password, token auth, atau kunci jawaban ke log.
- **DILARANG** menyediakan cara mengubah atau menghapus `log_aktivitas` lewat aplikasi.

## 8. Kualitas Kode dan Proses

- **DILARANG** mengganti stack atau library utama (Laravel, MySQL, Redis, Reverb, Sanctum) tanpa persetujuan.
- **DILARANG** menambah dependency baru tanpa menjelaskan alasannya, dan tanpa memastikan lisensinya aman dipakai.
- **WAJIB** mengikuti konvensi Laravel (Controller tipis, logika di Service/Action, validasi di Form Request, otorisasi di Policy).
- **WAJIB** setiap fitur baru atau perubahan logika disertai test (Pest/PHPUnit). Test yang gagal **tidak boleh** dihapus, di-skip, atau dilemahkan agar lulus.
- **WAJIB** format respons error seragam dan memakai kode error yang terdaftar di BE.md bagian 7. Kode baru harus ditambahkan ke BE.md.
- **WAJIB** memperbarui BE.md (endpoint, tabel, kode error) jika ada perubahan kontrak API atau skema.
- **DILARANG** meninggalkan kode debug (`dd()`, `dump()`, `var_dump`, `Log::debug` berisi data sensitif) di kode yang di-commit.
- **DILARANG** mengubah kode frontend dari tugas backend, kecuali diminta.
- **DILARANG** melakukan aksi destruktif atau irreversibel (deploy, push ke branch utama, hapus data, ubah server produksi) tanpa persetujuan eksplisit.

## 9. Bahasa

- Pesan error untuk pengguna dalam **bahasa Indonesia**. Nama kode, kelas, dan fungsi boleh bahasa Inggris atau Indonesia, tetapi **konsisten** dalam satu modul, dan nama tabel mengikuti BE.md bagian 5.

