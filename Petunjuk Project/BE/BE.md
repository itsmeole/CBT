# Requirement Backend: Website Ujian Sekolah (CBT)

> Sumber acuan: [`../ide.md`](../ide.md). Jika ada perbedaan, **keputusan di ide.md bagian 10** yang berlaku, lalu dokumen ini diperbarui.
> Aturan wajib untuk agent AI ada di [`Rules BE.md`](./Rules%20BE.md).
> Status: **Draft v1**.

---

## 1. Ringkasan

Backend menyediakan REST API, realtime (WebSocket), antrean job, dan penyimpanan data untuk CBT dengan 4 role (Superadmin, Operator, Guru, Siswa), 5 tipe soal, penguncian sesi ujian di server, dan penilaian otomatis.

**Konteks operasional (dari keputusan)**
- 660 siswa, ujian lewat **HP masing-masing**, server di **cloud/hosting**.
- Timer **tetap berjalan** saat sesi terkunci, tanpa kompensasi waktu.
- PG kompleks **all-or-nothing**.
- Siswa **tidak** pernah menerima nilai, skor, maupun kunci jawaban.
- Master data lewat **Excel**. Soal lewat **Word (.docx)**.
- Verifikasi kehadiran dan tombol **Mulai** hanya untuk **Operator** (dan Superadmin).

---

## 2. Stack (Default)

| Bagian | Pilihan |
|---|---|
| Framework | Laravel (versi stabil terbaru), PHP 8.3+ |
| API | REST JSON, prefix `/api/v1` |
| Autentikasi | Laravel Sanctum (token SPA) |
| Database | MySQL 8 (InnoDB, `utf8mb4`) |
| Cache, session, antrean | Driver Database (lokal dev) / Redis (VPS produksi) |
| Antrean | Laravel Queue (Database worker di lokal, Redis/Horizon di VPS) |
| Realtime | Laravel Reverb (WebSocket), dikonsumsi FE via Laravel Echo |
| Impor/ekspor Excel | `maatwebsite/excel` (PhpSpreadsheet) |
| Impor/ekspor Word | `phpoffice/phpword` / XML parser bawaan PHP (tanpa binary Pandoc) |
| Penyimpanan media | Disk lokal atau S3-compatible (via Laravel Filesystem) |
| Zona waktu | `Asia/Jakarta` untuk tampilan, simpan di DB dalam UTC |

> Pergantian stack atau library utama harus disetujui pemilik proyek (lihat Rules BE).

---

## 3. Role dan Otorisasi

### 3.1 Role

| Role | Ringkasan hak |
|---|---|
| `superadmin` | Semua fitur, kelola user, role, tahun ajaran, pengaturan sistem, log, backup |
| `operator` | Mapel, kelas, ruang, sesi, jadwal, token, bobot, plotting pengawas, **verifikasi kehadiran dan Mulai**, kartu peserta, reset login, lihat nilai |
| `guru` | Guru mapel: bank soal, paket soal, koreksi essay, nilai (mapel dan kelas yang diampu). Pengawas: monitoring, buka kunci, reset login, berita acara (sesi yang ditugaskan) |
| `siswa` | Login, input token, mengerjakan ujian |

### 3.2 Matriks Akses (wajib ditegakkan di server)

| Fitur | Superadmin | Operator | Guru | Siswa |
|---|:-:|:-:|:-:|:-:|
| Kelola user dan pengaturan sistem | ✅ | ❌ | ❌ | ❌ |
| Mapel, jadwal, sesi, token, bobot | ✅ | ✅ | ❌ | ❌ |
| Plotting pengawas | ✅ | ✅ | ❌ | ❌ |
| Verifikasi kehadiran dan Mulai ujian | ✅ | ✅ | ❌ | ❌ |
| Bank soal dan paket soal | ✅ | ❌ | ✅ (mapelnya) | ❌ |
| Koreksi essay | ✅ | ❌ | ✅ (mapelnya) | ❌ |
| Lihat/unduh nilai | ✅ | ✅ | ✅ (mapelnya) | ❌ |
| Monitoring dan buka kunci | ✅ | ✅ | ✅ (sesi yang diawasi) | ❌ |
| Mengerjakan ujian | ❌ | ❌ | ❌ | ✅ |

### 3.3 Aturan Scope Guru

- **Scope guru mapel:** akses soal, paket, essay, dan nilai hanya jika ada baris `guru_mapel` (guru, mapel, kelas, tahun ajaran) yang cocok.
- **Scope pengawas:** akses monitoring, buka kunci, reset login, dan berita acara hanya untuk sesi di `pengawas_sesi` miliknya.
- Guru yang mengawas mapel lain **tidak boleh** menerima konten soal, kunci, jawaban, maupun nilai mapel tersebut. Data monitoring untuk pengawas hanya berisi: nama, NIS, kelas, status, jumlah soal terjawab, jumlah kunci, sisa waktu.
- Implementasi: Laravel Policy/Gate per resource + query scope. Tidak cukup hanya menyembunyikan menu di FE.

---

## 4. Requirement Fungsional

Penomoran `FR-BE-xx` dipakai sebagai referensi di task dan test.

### 4.1 Autentikasi dan Sesi Perangkat

- **FR-BE-01** Login dengan username (NIS/NIP/username) + password. Password di-hash (bcrypt/argon2).
- **FR-BE-02** Rate limit login: maksimal 5 percobaan per menit per username + IP.
- **FR-BE-03** **Satu akun, satu perangkat aktif.** Kebijakan diatur Superadmin:
  - `tolak` (default): login baru ditolak dengan pesan "Akun sedang aktif di perangkat lain, hubungi operator/pengawas".
  - `kick`: sesi lama dicabut, perangkat lama menerima event `ForceLogout`.
- **FR-BE-04** Reset login oleh Operator, Superadmin, atau guru pengawas sesi tersebut: mencabut token aktif siswa, **tidak** menghapus jawaban, **tidak** mengubah `waktu_selesai`. Dicatat di log.
- **FR-BE-05** Logout mencabut token.
- **FR-BE-06** Endpoint `GET /api/v1/time` mengembalikan waktu server (ms epoch) untuk sinkronisasi tampilan timer FE.

### 4.2 Master Data dan Impor/Ekspor Excel

- **FR-BE-10** CRUD: tahun ajaran (dan semester aktif), user, kelas/rombel, siswa, guru, mapel, guru_mapel, ruang.
- **FR-BE-11** Impor Excel untuk siswa, guru, kelas, guru_mapel. Alur 2 langkah:
  1. Unggah, lalu validasi semua baris, kembalikan **pratinjau** + **daftar error per baris** (nomor baris, kolom, pesan). Belum ada data yang disimpan.
  2. Konfirmasi, lalu simpan dalam satu transaksi (semua atau tidak sama sekali), dijalankan lewat queue untuk file besar.
- **FR-BE-12** Sediakan unduhan template Excel resmi per jenis data.
- **FR-BE-13** Ekspor Excel: data siswa, master data, rekap nilai.
- **FR-BE-14** **Bank soal tidak boleh diimpor/diekspor lewat Excel.**

### 4.3 Bank Soal

- **FR-BE-20** CRUD soal oleh guru mapel dalam scope-nya. 5 tipe: `pg`, `pg_kompleks`, `benar_salah`, `menjodohkan`, `essay`.
- **FR-BE-21** Struktur per tipe:

| Tipe | Konten | Kunci |
|---|---|---|
| `pg` | Teks soal, 2 sampai 5 opsi | Tepat 1 opsi benar |
| `pg_kompleks` | Teks soal, 2 sampai 6 opsi | Minimal 2 opsi benar |
| `benar_salah` | Teks soal + 1 atau lebih pernyataan | Benar/Salah per pernyataan |
| `menjodohkan` | Daftar premis (kiri) dan daftar respons (kanan, boleh lebih banyak dari premis) | Pasangan premis ke respons |
| `essay` | Teks soal | Kunci jawaban + rubrik (teks), skor diinput guru |

- **FR-BE-22** Konten soal dan opsi berupa HTML terbatas (whitelist tag) + LaTeX untuk rumus (dirender KaTeX di FE) + referensi media (gambar, audio).
- **FR-BE-23** **Sanitasi HTML** di server saat simpan dan impor (cegah XSS). Whitelist: `p, br, b, strong, i, em, u, sub, sup, ul, ol, li, table, thead, tbody, tr, th, td, img, span[data-latex]`.
- **FR-BE-24** Unggah media: gambar (`jpg, png, webp`, maks 2 MB, dikompres/di-resize ke lebar maks 1280 px), audio (`mp3, m4a`, maks 10 MB). Validasi MIME asli, bukan hanya ekstensi.
- **FR-BE-25** Soal yang sudah dipakai di paket yang **sudah dijadwalkan atau berjalan** tidak boleh diubah isinya secara langsung. Perubahan membuat versi baru, atau ditolak dengan pesan jelas.

### 4.4 Impor/Ekspor Soal lewat Word (.docx)

- **FR-BE-30** Impor dan ekspor soal **hanya** dalam format `.docx`.
- **FR-BE-31** Sediakan unduhan **template Word resmi**. Format penanda (draft, final ditetapkan saat pembuatan template):

```text
[SOAL]
TIPE: PG
TEKS:
Ibu kota Indonesia adalah ...
A. Bandung
B. Jakarta
C. Surabaya
D. Medan
KUNCI: B
[/SOAL]

[SOAL]
TIPE: PG_KOMPLEKS
TEKS: Pilih semua bilangan prima.
A. 2
B. 4
C. 5
D. 9
KUNCI: A, C
[/SOAL]

[SOAL]
TIPE: BENAR_SALAH
TEKS: Tentukan benar atau salah.
1. Air mendidih pada 100 °C di permukaan laut. | B
2. Matahari mengelilingi bumi. | S
[/SOAL]

[SOAL]
TIPE: MENJODOHKAN
TEKS: Jodohkan negara dengan ibu kotanya.
KIRI:
1. Jepang
2. Prancis
KANAN:
a. Paris
b. Tokyo
c. Roma
KUNCI: 1-b, 2-a
[/SOAL]

[SOAL]
TIPE: ESSAY
TEKS: Jelaskan proses fotosintesis.
KUNCI: Tumbuhan mengubah cahaya, air, dan CO2 menjadi glukosa dan O2.
RUBRIK: Skor penuh jika menyebut 4 komponen utama.
[/SOAL]
```

- **FR-BE-32** Gambar dan tabel dalam Word diekstrak, gambar disimpan ke storage lalu direferensikan dari konten soal.
- **FR-BE-33** Rumus Equation Editor Word (OMML) dikonversi ke LaTeX (via XSLT / native XML parser PHP, tanpa dependensi binary Pandoc). Rumus yang gagal dikonversi ditandai sebagai error/peringatan, bukan diabaikan diam-diam.
- **FR-BE-34** Audio tidak didukung lewat Word. Ditambahkan lewat editor soal setelah impor.
- **FR-BE-35** Alur impor 2 langkah seperti Excel: pratinjau + **laporan error per soal** (nomor soal, pesan: kunci kosong, tipe tidak dikenal, jumlah opsi tidak valid, dsb.), lalu konfirmasi dan simpan dalam satu transaksi.
- **FR-BE-36** Ekspor bank soal/paket ke `.docx` dengan format **identik dengan template**, sehingga file hasil ekspor bisa diimpor ulang tanpa perubahan (round-trip). Wajib ada test round-trip.
- **FR-BE-37** File Word diproses di queue, dengan batas ukuran (default 20 MB) dan timeout.

### 4.5 Paket Soal dan Bobot

- **FR-BE-40** Guru menyusun paket soal dari bank soal miliknya (mapel yang sama).
- **FR-BE-41** Operator mengatur **bobot per tipe soal** (skor maksimal per soal untuk tiap tipe).
- **FR-BE-42** **Snapshot bobot**: saat jadwal ujian dibuat dari sebuah paket, bobot per tipe dan daftar soal disalin ke `paket_soal_item`/jadwal. Perubahan bobot global setelahnya **tidak** memengaruhi jadwal yang sudah dibuat.
- **FR-BE-43** Paket yang sudah dipakai jadwal yang berjalan/selesai bersifat read-only.

### 4.6 Jadwal, Sesi, Ruang, Token, Plotting

- **FR-BE-50** Operator membuat jadwal ujian: mapel, paket, kelas peserta, tanggal, durasi (menit), tahun ajaran, semester.
- **FR-BE-51** Operator membuat sesi dan ruang, lalu memetakan peserta ke sesi-ruang. Dukung 2 sampai 3 gelombang sesi.
- **FR-BE-52** Plotting guru pengawas ke sesi-ruang (`pengawas_sesi`). Satu guru tidak boleh mengawas dua sesi yang waktunya bentrok.
- **FR-BE-53** Token ujian per jadwal/sesi: 6 karakter alfanumerik huruf besar, tanpa karakter ambigu (`0/O`, `1/I`). Operator dapat generate ulang. Token lama langsung tidak berlaku.
- **FR-BE-54** Sesi khusus untuk ujian susulan/remedial dengan daftar peserta tertentu.
- **FR-BE-55** Kartu peserta, daftar hadir, berita acara, rekap nilai dapat dicetak (PDF).

### 4.7 Pelaksanaan Ujian

Status peserta (`peserta_ujian.status`):

```text
belum_login -> menunggu -> mengerjakan <-> terkunci -> selesai
```

- **FR-BE-60 Join:** siswa memasukkan token. Server memvalidasi: token cocok, jadwal hari ini, siswa terdaftar di sesi-ruang tersebut, sesi belum ditutup. Jika valid, status menjadi `menunggu`.
- **FR-BE-61 Verifikasi dan Mulai (hanya Operator/Superadmin):**
  - Operator menandai kehadiran per siswa (hadir/tidak hadir).
  - Operator menekan **Mulai** per sesi-ruang (untuk semua siswa terverifikasi) atau per siswa (untuk yang terlambat).
  - Server menetapkan `waktu_mulai` dan `waktu_selesai = waktu_mulai + durasi`, tidak melewati batas akhir sesi.
  - Guru pengawas **ditolak** (403) jika memanggil endpoint ini.
- **FR-BE-62 Pengacakan:** urutan soal dan urutan opsi diacak per siswa saat Mulai, lalu disimpan (`urutan_soal`, `urutan_opsi`) agar urutannya **tetap sama** setelah reload atau ganti perangkat.
- **FR-BE-63 Pengiriman soal:** payload soal ke siswa **tidak boleh** berisi kunci jawaban, rubrik, bobot, atau penanda opsi benar. ID opsi yang dikirim tidak boleh membocorkan urutan asli (gunakan ID acak/UUID).
- **FR-BE-64 Autosave:**
  - Endpoint menerima jawaban **per soal** (hanya yang berubah), satu atau beberapa sekaligus (batch).
  - Setiap jawaban membawa `client_seq` (angka naik per peserta). Server mengabaikan jawaban dengan `client_seq` lebih kecil dari yang tersimpan (cegah data basi menimpa data baru).
  - Idempoten: kiriman ulang yang sama tidak menimbulkan duplikat.
  - **Ditolak** jika status `terkunci`, `selesai`, atau waktu server sudah lewat `waktu_selesai`.
  - Respons berisi status peserta dan `waktu_selesai` terbaru.
- **FR-BE-65 Timer di server:** satu-satunya sumber kebenaran adalah `waktu_selesai` di DB. Tidak ada endpoint yang menerima sisa waktu dari client.
- **FR-BE-66 Submit manual:** mengubah status menjadi `selesai`, mengunci jawaban, lalu memasukkan job penilaian.
- **FR-BE-67 Auto-submit:** scheduler/queue memeriksa setiap ≤ 30 detik dan men-submit peserta yang `waktu_selesai` sudah lewat, **termasuk yang berstatus `terkunci`**. Setiap request peserta yang melewati waktu juga memicu auto-submit.
- **FR-BE-68 Recovery:** endpoint state peserta mengembalikan status, `waktu_selesai`, urutan soal, dan semua jawaban tersimpan, sehingga FE bisa melanjutkan setelah reload, mati listrik, atau ganti perangkat (setelah reset login).
- **FR-BE-69** Setelah `selesai`, siswa hanya menerima status `selesai`. Tidak ada nilai, skor, atau pembahasan.

### 4.8 Kunci Sesi (tanpa toleransi)

- **FR-BE-70** Endpoint `lock` dipanggil FE saat terdeteksi keluar halaman (pindah tab, minimize, pindah aplikasi, keluar fullscreen, layar mati, reload/tutup tab). Server **langsung** mengubah status menjadi `terkunci` dan membuat baris `lock_event` (waktu kunci, pemicu, user agent). Tidak ada ambang atau toleransi.
- **FR-BE-71** Lock event yang tertunda karena offline dikirim FE saat koneksi pulih. Server tetap mengunci meskipun event datang terlambat (selama status belum `selesai`).
- **FR-BE-72** Selama `terkunci`, semua endpoint tulis jawaban menolak dengan kode error `SESSION_LOCKED`.
- **FR-BE-73** Buka kunci hanya oleh guru pengawas sesi tersebut, Operator, atau Superadmin, dengan **alasan wajib** (minimal 5 karakter). Update `lock_event`: waktu buka, pembuka, alasan.
- **FR-BE-74** **Timer tetap berjalan** saat terkunci. Buka kunci **tidak** mengubah `waktu_selesai`. Tidak ada fitur kompensasi waktu.
- **FR-BE-75** Hitung jumlah kunci per peserta. Jika mencapai ambang (default 3, diatur Operator), kirim peringatan ke dashboard pengawas.
- **FR-BE-76** Setiap kunci dan buka kunci di-broadcast realtime (lihat bagian 6).

### 4.9 Penilaian

- **FR-BE-80** Penilaian otomatis dijalankan di queue setelah submit (manual/otomatis), memakai **bobot snapshot**.
- **FR-BE-81** Aturan skor per soal (`B` = bobot snapshot tipe tersebut):

| Tipe | Skor |
|---|---|
| `pg` | `B` jika benar, selain itu 0 |
| `pg_kompleks` | `B` hanya jika himpunan pilihan **persis sama** dengan kunci (all-or-nothing), selain itu 0 |
| `benar_salah` | `B × (pernyataan benar / total pernyataan)` |
| `menjodohkan` | `B × (pasangan benar / total premis)` |
| `essay` | Diinput guru, rentang 0 sampai `B` |

- **FR-BE-82** `Nilai akhir = (total skor diperoleh / total skor maksimal) × 100`, dibulatkan 2 desimal. Perhitungan memakai tipe desimal, bukan float.
- **FR-BE-83** Jika ada essay yang belum dikoreksi, status nilai `menunggu_koreksi` dan nilai akhir dianggap sementara.
- **FR-BE-84** Koreksi essay oleh guru mapel dalam scope-nya: input skor per jawaban + catatan opsional. Setelah semua essay dikoreksi, nilai akhir dihitung ulang dan status menjadi `final`.
- **FR-BE-85** Fungsi penilaian bersifat **murni dan deterministik** (input jawaban + kunci + bobot, keluar skor), dan wajib dicakup unit test untuk setiap tipe dan kasus tepi.
- **FR-BE-86** Analisis butir soal per jadwal: tingkat kesukaran (p) dan daya pembeda (D), untuk guru mapel/Operator/Superadmin.

### 4.10 Nilai dan Laporan

- **FR-BE-90** Guru mapel melihat dan mengunduh nilai hanya untuk mapel dan kelas yang diampu. Operator dan Superadmin melihat semua.
- **FR-BE-91** Ekspor rekap nilai ke Excel dan PDF.
- **FR-BE-92** **Tidak ada** endpoint yang mengembalikan nilai untuk role `siswa`.

### 4.11 Monitoring, Log, Berita Acara, Backup

- **FR-BE-100** Endpoint monitoring per sesi-ruang: daftar peserta, status, jumlah terjawab, jumlah kunci, sisa waktu (dihitung server).
- **FR-BE-101** Log aktivitas (append-only): login, logout, login ditolak, reset login, join token, mulai, keluar halaman/kunci, buka kunci, submit, auto-submit, impor, ekspor, perubahan bobot, perubahan nilai essay. Simpan aktor, target, waktu, IP, user agent, data tambahan (JSON).
- **FR-BE-102** Berita acara per sesi-ruang: diisi pengawas (jumlah hadir/tidak hadir, kejadian, catatan siswa yang sering terkunci), dapat dicetak.
- **FR-BE-103** Backup database harian otomatis (retensi minimal 14 hari) + backup manual oleh Superadmin. Prosedur restore terdokumentasi dan pernah diuji.

---

## 5. Model Data (Garis Besar)

> Detail kolom dan relasi difinalkan di ERD. Semua tabel ujian terkait `tahun_ajaran_id` (dan semester).

| Tabel | Kolom kunci (ringkas) |
|---|---|
| `users` | id, username (unik), nama, password, role, aktif |
| `tahun_ajaran` | id, nama, semester, aktif |
| `kelas` | id, nama, tingkat, tahun_ajaran_id |
| `siswa` | id, user_id, nis (unik), kelas_id |
| `guru` | id, user_id, nip |
| `mapel` | id, kode, nama |
| `guru_mapel` | guru_id, mapel_id, kelas_id, tahun_ajaran_id |
| `ruang` | id, nama, kapasitas |
| `bank_soal` | id, guru_id, mapel_id, tipe, konten (HTML), kunci (JSON), rubrik, versi |
| `opsi_soal` | id, soal_id, konten, sisi (untuk menjodohkan), urutan |
| `media` | id, path, mime, ukuran, pemilik |
| `bobot_tipe` | tipe, bobot (pengaturan global operator) |
| `paket_soal` | id, mapel_id, guru_id, nama |
| `paket_soal_item` | paket_id, soal_id, urutan |
| `jadwal_ujian` | id, mapel_id, paket_id, tanggal, durasi_menit, bobot_snapshot (JSON), tahun_ajaran_id |
| `sesi_ruang` | id, jadwal_id, ruang_id, sesi_ke, jam_mulai, jam_akhir, jenis (reguler/susulan) |
| `pengawas_sesi` | sesi_ruang_id, guru_id |
| `token` | id, jadwal_id / sesi_ruang_id, kode, aktif, dibuat_oleh |
| `peserta_ujian` | id, siswa_id, sesi_ruang_id, status, hadir, waktu_mulai, waktu_selesai, urutan_soal (JSON), urutan_opsi (JSON), jumlah_kunci, last_client_seq |
| `jawaban_siswa` | peserta_id, soal_id, jawaban (JSON), client_seq, skor, dikoreksi_oleh, updated_at |
| `nilai` | peserta_id, skor_per_tipe (JSON), total, maksimal, nilai_akhir, status (`menunggu_koreksi`/`final`) |
| `lock_event` | id, peserta_id, pemicu, waktu_kunci, waktu_buka, dibuka_oleh, alasan |
| `log_aktivitas` | id, user_id, aksi, target_type, target_id, ip, user_agent, data (JSON), created_at |
| `berita_acara` | sesi_ruang_id, pengawas_id, isi, jumlah_hadir, jumlah_absen |
| `device_session` | user_id, token_id, device_info, aktif |

**Catatan**
- `lock_event` **tidak** memiliki kolom kompensasi waktu (sesuai keputusan timer tetap berjalan).
- Indeks wajib: `peserta_ujian(sesi_ruang_id, status)`, `jawaban_siswa(peserta_id, soal_id)` unik, `token(kode, aktif)`, `log_aktivitas(created_at)`.

---

## 6. Realtime (WebSocket)

| Channel (private) | Pelanggan | Event |
|---|---|---|
| `sesi.{sesiRuangId}` | Pengawas sesi tersebut, Operator, Superadmin | `PesertaStatusChanged`, `PesertaLocked`, `PesertaUnlocked`, `PesertaProgress` (di-throttle, maks 1x per 10 detik per peserta), `LockThresholdReached`, `PesertaSubmitted` |
| `peserta.{pesertaId}` | Siswa pemilik | `UjianDimulai`, `SesiUnlocked`, `ForceLogout`, `WaktuHabis` |

- Otorisasi channel wajib memakai aturan scope yang sama dengan API (bagian 3.3).
- Payload event untuk pengawas **tidak** berisi konten soal, jawaban, atau nilai.
- Realtime hanya untuk notifikasi. Keputusan status tetap dibaca dari DB lewat API (FE harus bisa jalan dengan polling fallback).

---

## 7. Kontrak API (Draft)

Format respons error seragam:

```json
{ "error": { "code": "SESSION_LOCKED", "message": "Ujian terkunci, hubungi pengawas." } }
```

Kode error minimal: `UNAUTHENTICATED`, `FORBIDDEN`, `VALIDATION_ERROR`, `ACCOUNT_IN_USE`, `TOKEN_INVALID`, `NOT_STARTED`, `SESSION_LOCKED`, `TIME_UP`, `ALREADY_SUBMITTED`, `RATE_LIMITED`.

| Method | Endpoint | Role |
|---|---|---|
| POST | `/auth/login`, `/auth/logout` | Semua |
| GET | `/me`, `/time` | Semua |
| POST | `/auth/reset-login/{userId}` | Operator, Superadmin, Pengawas (sesinya) |
| CRUD | `/tahun-ajaran`, `/users`, `/settings` | Superadmin |
| CRUD | `/kelas`, `/siswa`, `/guru`, `/mapel`, `/guru-mapel`, `/ruang` | Superadmin, Operator |
| POST | `/import/excel/{jenis}/preview`, `/import/excel/{jenis}/commit` | Superadmin, Operator |
| GET | `/export/excel/{jenis}`, `/templates/excel/{jenis}` | Superadmin, Operator |
| CRUD | `/bank-soal`, `/paket-soal` | Guru (scope), Superadmin |
| POST | `/media` | Guru, Superadmin |
| POST | `/import/word/preview`, `/import/word/commit` | Guru (scope), Superadmin |
| GET | `/export/word/bank-soal`, `/export/word/paket/{id}`, `/templates/word` | Guru (scope), Superadmin |
| PUT | `/bobot-tipe` | Operator, Superadmin |
| CRUD | `/jadwal`, `/sesi-ruang`, `/pengawas-sesi` | Operator, Superadmin |
| POST | `/sesi-ruang/{id}/token/generate` | Operator, Superadmin |
| POST | `/sesi-ruang/{id}/kehadiran` | **Operator, Superadmin** |
| POST | `/sesi-ruang/{id}/mulai`, `/peserta/{id}/mulai` | **Operator, Superadmin** |
| GET | `/sesi-ruang/{id}/monitoring` | Operator, Superadmin, Pengawas (sesinya) |
| POST | `/peserta/{id}/unlock` | Operator, Superadmin, Pengawas (sesinya) |
| CRUD | `/sesi-ruang/{id}/berita-acara` | Pengawas (sesinya), Operator, Superadmin |
| POST | `/ujian/join` (token) | Siswa |
| GET | `/ujian/state` | Siswa |
| GET | `/ujian/soal` | Siswa (status `mengerjakan`) |
| PUT | `/ujian/jawaban` (batch) | Siswa |
| POST | `/ujian/lock` | Siswa |
| POST | `/ujian/submit` | Siswa |
| GET | `/koreksi/essay`, PUT `/koreksi/essay/{jawabanId}` | Guru (scope), Superadmin |
| GET | `/nilai`, `/nilai/export`, `/analisis-butir/{jadwalId}` | Guru (scope), Operator, Superadmin |
| GET | `/log-aktivitas` | Superadmin |
| POST | `/backup` | Superadmin |

---

## 8. Requirement Non-Fungsional

### 8.1 Kinerja dan Kapasitas
- **NFR-BE-01** Mampu menangani **700 koneksi bersamaan** (660 siswa + staf) dengan asumsi terburuk semua mulai bersamaan.
- **NFR-BE-02** Target latensi p95: autosave < 300 ms, lock < 300 ms, join/state < 500 ms (diukur di server region Jakarta/Singapura).
- **NFR-BE-03** Endpoint autosave ringan: tanpa query N+1, tulis langsung ke tabel jawaban (atau buffer Redis lalu flush), tanpa memuat ulang seluruh lembar.
- **NFR-BE-04** Penilaian, impor, ekspor, dan cetak PDF berjalan di queue, tidak memblokir request.
- **NFR-BE-05** Wajib **load test** (k6/JMeter) dengan skenario 700 virtual user: login, join, ambil soal, autosave tiap 5 sampai 15 detik, lock/unlock acak, submit serentak. Hasil didokumentasikan sebelum hari ujian.
- **NFR-BE-06** Spesifikasi server minimal: 4 vCPU / 8 GB RAM, Redis, region Jakarta atau Singapura.

### 8.2 Keamanan
- **NFR-BE-10** HTTPS wajib. Cookie `Secure`, `HttpOnly`, `SameSite`.
- **NFR-BE-11** Otorisasi di setiap endpoint (Policy) + scope query. Default menolak.
- **NFR-BE-12** Validasi semua input dengan Form Request. Sanitasi HTML konten soal.
- **NFR-BE-13** Kunci jawaban, rubrik, dan nilai tidak pernah dikirim ke siswa, termasuk lewat event realtime, pesan error, atau log yang bisa diakses siswa.
- **NFR-BE-14** Rate limit: login, join token, lock, autosave (wajar untuk 1 siswa).
- **NFR-BE-15** Rahasia (DB, Redis, Reverb, S3) hanya di `.env`, tidak di-commit.
- **NFR-BE-16** Validasi opsional header Safe Exam Browser untuk sesi yang mewajibkan SEB.

### 8.3 Keandalan dan Data
- **NFR-BE-20** Operasi kritis (submit, unlock, mulai, commit impor) memakai transaksi DB dan aman dari race condition (row lock / update bersyarat pada status).
- **NFR-BE-21** Tidak ada penghapusan fisik data ujian (jawaban, nilai, lock_event, log). Gunakan soft delete untuk master data.
- **NFR-BE-22** Migrasi database berversi dan dapat di-rollback.
- **NFR-BE-23** Health check endpoint untuk monitoring server.

---

## 9. Testing dan Kriteria Penerimaan

**Wajib ada test otomatis (Pest/PHPUnit) untuk:**
- Penilaian 5 tipe, termasuk kasus tepi: kosong, pilihan sebagian PG kompleks (harus 0), benar/salah sebagian, menjodohkan sebagian, bobot snapshot vs bobot global yang diubah.
- Otorisasi: guru pengawas tidak bisa melihat soal/nilai mapel lain, guru pengawas tidak bisa Mulai/verifikasi, siswa tidak bisa mengakses endpoint nilai.
- Kunci: autosave ditolak saat terkunci, unlock tanpa alasan ditolak, unlock tidak mengubah `waktu_selesai`.
- Timer: autosave setelah `waktu_selesai` ditolak, auto-submit peserta terkunci.
- Login ganda sesuai kebijakan `tolak`/`kick`.
- Autosave `client_seq` lama tidak menimpa data baru.
- Impor Word: tiap tipe soal, error per soal, round-trip ekspor lalu impor menghasilkan data identik.
- Impor Excel: error per baris, transaksi semua atau tidak sama sekali.

**Definition of Done per fitur:** test lulus, otorisasi diuji, log aktivitas tercatat (jika relevan), dokumentasi endpoint diperbarui.

---

## 10. Tahapan (mengikuti ide.md)

| Tahap | Cakupan backend |
|---|---|
| **1. MVP** | Auth 4 role, master data + impor Excel, bank soal 5 tipe + impor Word, paket soal, bobot + snapshot, jadwal/sesi/token, join, Mulai oleh operator, autosave, timer server, submit/auto-submit, penilaian otomatis |
| **2. Pengawasan dan penilaian** | Kunci sesi + lock_event, realtime monitoring, plotting pengawas, unlock dengan alasan, reset login, koreksi essay, rekap nilai |
| **3. Penyempurnaan** | Log aktivitas lengkap, berita acara, ekspor Excel/Word, cetak PDF, analisis butir, ujian susulan, dukungan SEB/wrapper, load test |

