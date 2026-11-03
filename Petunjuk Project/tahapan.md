# Roadmap & Todo List Implementasi CBT (BE & FE)

Dokumen ini adalah panduan kerja teknis berbentuk *checklist* terpadu untuk Backend (BE) dan Frontend (FE) berdasarkan [`ide.md`](./ide.md), [`BE/BE.md`](./BE/BE.md), dan [`FE/FE.md`](./FE/FE.md).

Aturan kerja agen AI wajib mematuhi [`BE/Rules BE.md`](./BE/Rules%20BE.md) dan [`FE/Rules FE.md`](./FE/Rules%20FE.md).

---

## Ringkasan Progres Utama

- [x] **Fase 0:** Setup Arsitektur & Lingkungan Kerja
- [ ] **Fase 1 (MVP):** Autentikasi, Master Data, Bank Soal, Pengerjaan Ujian, & Penilaian Otomatis
- [ ] **Fase 2:** Kunci Sesi Realtime, Pengawasan, & Koreksi Essay
- [ ] **Fase 3:** Rekap Nilai, Laporan/Cetak, Audit Log, & Uji Beban (Load Test 700 User)

---

## Fase 0: Setup Arsitektur & Fondasi Proyek

### Backend (BE)
- [x] Inisialisasi proyek Laravel (PHP 8.3+) dengan struktur folder modular/service-oriented.
- [x] Konfigurasi database MySQL 8 (`utf8mb4`, zona waktu DB: UTC).
- [x] Setup driver Database/Cache untuk Queue & Session lokal (persiapan Redis/Horizon saat deploy VPS produksi).
- [x] Setup Laravel Sanctum untuk SPA Authentication.
- [x] Setup Laravel Reverb untuk WebSocket server dan event broadcasting.
- [x] Setup Form Request validation & standardisasi format JSON Response (termasuk standardisasi format error code).
- [x] Setup logging channel & sistem exception handler terpusat.
- [x] Konfigurasi library file parser (`maatwebsite/excel` & `phpoffice/phpword` / native XML untuk `.docx`, tanpa Pandoc).

### Frontend (FE)
- [x] Inisialisasi proyek Vue 3 + Vite + TypeScript (mode `strict`).
- [x] Setup Tailwind CSS & desain sistem antarmuka responsif (mobile-first untuk halaman siswa).
- [x] Setup Vue Router dengan Route Guards terpusat berbasis Role.
- [x] Setup Pinia untuk state management global (Auth, Sesi Ujian, Monitoring).
- [x] Setup HTTP Client (Axios) dengan Interceptor terpusat (penanganan token, error global, network offline).
- [x] Setup Laravel Echo & Pusher JS client untuk integrasi ke Laravel Reverb.
- [x] Setup KaTeX untuk perenderan formula matematika/LaTeX.
- [x] Setup IndexedDB wrapper (`idb`/`localforage`) untuk antrean penyimpanan offline.
- [x] Setup tooling testing: Vitest (unit test).

---

## Fase 1 (MVP): Core Engine Ujian & Penilaian Otomatis

### 1.1 Autentikasi & Manajemen Pengguna
#### Backend
- [x] Migration tabel `users`, `roles`, `tahun_ajaran`, `device_session`.
- [x] Implementasi login username + password dengan hashing aman (`FR-BE-01`).
- [x] Rate limiting login (maks. 5 percobaan/menit per user + IP) (`FR-BE-02`).
- [x] Implementasi single active session / satu akun satu perangkat (khusus role `siswa`) (`FR-BE-03`).
- [x] Endpoint `GET /api/v1/time` untuk sinkronisasi waktu server (`FR-BE-06`).
- [x] Policy & Middleware otorisasi untuk 4 role (Superadmin, Operator, Guru, Siswa).

#### Frontend
- [x] Halaman login responsif dengan pesan error berbahasa Indonesia (`FR-FE-01`).
- [x] Penanganan state autentikasi di Pinia & penyimpanan session aman (tanpa token di `localStorage`).
- [x] Layout terpisah: Layout Siswa (minimalis/mobile) vs Layout Staf/Admin (desktop-first).

---

### 1.2 Master Data & Impor Excel
#### Backend
- [x] Migration tabel `kelas`, `siswa`, `guru`, `mapel`, `guru_mapel`, `ruang`.
- [x] CRUD Master data untuk Operator & Superadmin (`FR-BE-10`).
- [x] Endpoint unduh template Excel resmi untuk siswa, guru, kelas, guru_mapel (`FR-BE-12`).
- [x] Impor Excel tahap 1: Unggah & validasi baris, return pratinjau + laporan error per baris (`FR-BE-11`).
- [x] Impor Excel tahap 2: Commit data via database transaction dalam antrean queue (`FR-BE-11`).
- [x] Proteksi ketat: larang impor/ekspor soal lewat Excel (`FR-BE-14`).

#### Frontend
- [x] UI CRUD Master data untuk Operator (tabel, pencarian, filter, paginasi).
- [x] Wizard Impor Excel 2 langkah (Upload -> Pratinjau Error per Baris -> Konfirmasi Simpan) (`FR-FE-121`).
- [x] Tombol unduh template Excel resmi di masing-masing master data.

---

### 1.3 Bank Soal, Unggah Media, & Impor Word (.docx)
#### Backend
- [x] Migration tabel `bank_soal`, `opsi_soal`, `media`.
- [x] Logika isolasi scope guru mapel: hanya mengelola bank soal untuk mapel miliknya.
- [x] CRUD Bank Soal 5 tipe (`pg`, `pg_kompleks`, `benar_salah`, `menjodohkan`, `essay`) (`FR-BE-20`, `FR-BE-21`).
- [x] Sanitasi HTML konten soal (whitelist tag aman) (`FR-BE-23`).
- [x] Endpoint unggah media (gambar webp/jpg/png maks 2MB auto-resize, audio maks 10MB) (`FR-BE-24`).
- [x] Parser dokumen Word (`.docx`) berdasarkan format baku `[SOAL] ... [/SOAL]` (`FR-BE-31`).
- [x] Ekstraksi gambar embedded dan konversi rumus Word OMML ke LaTeX KaTeX (`FR-BE-32`, `FR-BE-33`).
- [x] Endpoint pratinjau impor Word + validasi error per butir soal (`FR-BE-35`).
- [x] Endpoint commit hasil impor Word ke database (single transaction) (`FR-BE-35`).
- [x] Ekspor bank soal ke `.docx` dengan format template identik (mendukung *round-trip*) (`FR-BE-36`).

#### Frontend
- [x] Tampilan daftar bank soal dengan filter mapel, tipe soal, dan pencarian kata kunci.
- [x] Integrasi Rich Text Editor dengan dukungan gambar, tabel, dan input formula LaTeX/KaTeX (`FR-FE-101`).
- [x] Form dinamis pembuatan soal sesuai 5 tipe (`FR-FE-101`).
- [x] Wizard Impor Soal dari Word (`.docx`): Upload -> Pratinjau Soal & Laporan Error -> Konfirmasi (`FR-FE-111`).
- [x] Validasi form client-side: blokir commit jika masih ada soal berstatus error (`FR-FE-111`).
- [x] Tombol ekspor bank soal ke format Word (`FR-FE-113`).

---

### 1.4 Paket Soal, Bobot, Jadwal, & Kontrol Operator
#### Backend
- [x] Migration tabel `bobot_tipe`, `paket_soal`, `paket_soal_item`, `jadwal_ujian`, `sesi_ruang`, `token`.
- [x] Manajemen paket soal oleh guru mapel (`FR-BE-40`).
- [x] Konfigurasi bobot per tipe soal oleh Operator (`FR-BE-41`).
- [x] Logika **Snapshot Bobot**: duplikasi bobot ke jadwal saat jadwal diterbitkan (`FR-BE-42`).
- [x] Manajemen jadwal, sesi, ruang, dan pemetaan rombel/peserta (`FR-BE-50`, `FR-BE-51`).
- [x] Generator Token Ujian 6 karakter alfanumerik huruf besar tanpa karakter ambigu (`FR-BE-53`).
- [x] Endpoint presensi/verifikasi kehadiran siswa oleh **Operator/Superadmin saja** (`FR-BE-61`).
- [x] Endpoint aksi **Mulai Ujian** oleh **Operator/Superadmin saja** (return 403 Forbidden untuk Guru) (`FR-BE-61`).

#### Frontend
- [x] Halaman kelola paket soal oleh guru mapel (`FR-FE-104`).
- [x] Halaman konfigurasi bobot tipe soal oleh Operator.
- [x] Halaman manajemen jadwal, sesi, ruang, dan generate token oleh Operator.
- [x] Panel Kontrol Sesi Operator: daftar presensi siswa per ruang & tombol verifikasi kehadiran (`FR-FE-80`, `FR-FE-81`).
- [x] Tombol **Mulai Ujian** (per sesi atau per siswa) dengan dialog konfirmasi (`FR-FE-82`).
- [x] Pastikan menu dan tombol kontrol mulai ujian disembunyikan total dari role Guru (`FR-FE-83`).

---

### 1.5 Pengerjaan Ujian Siswa (Mobile-First) & Penilaian Otomatis
#### Backend
- [x] Migration tabel `peserta_ujian`, `jawaban_siswa`, `nilai`.
- [x] Endpoint validasi token & join ujian -> status beralih ke `menunggu` (`FR-BE-60`).
- [x] Pengacakan urutan soal dan opsi saat sesi dimulai, simpan deterministik di DB (`FR-BE-62`).
- [x] Endpoint payload soal siswa: **strip total** kunci jawaban, skor, dan rubrik (`FR-BE-63`).
- [x] Endpoint autosave jawaban: hanya simpan delta perubahan, proteksi `client_seq`, cek batas waktu (`FR-BE-64`).
- [x] Penegakan timer di server berdasarkan `waktu_selesai` absolut (`FR-BE-65`).
- [x] Endpoint submit manual siswa (`FR-BE-66`).
- [x] Background job scheduler auto-submit saat waktu habis (termasuk status terkunci) (`FR-BE-67`).
- [x] Engine kalkulasi nilai otomatis untuk PG, PG Kompleks (*all-or-nothing*), B/S, dan Menjodohkan (`FR-BE-81`, `FR-BE-82`).
- [x] Proteksi ketat: jangan kirim nilai ke siswa setelah selesai (`FR-BE-69`, `FR-BE-92`).

#### Frontend
- [x] Form input token ujian siswa dengan checklist persiapan perangkat HP (`FR-FE-02`, `FR-FE-03`).
- [x] Halaman Ruang Tunggu siswa: polling/mendengarkan sinyal mulai dari Operator (`FR-FE-10`, `FR-FE-11`).
- [x] Halaman pengerjaan ujian mobile-first: layout ringkas, nomor grid, touch target minimal 44x44px (`FR-FE-20`, `FR-FE-21`).
- [x] Komponen input khusus untuk 5 tipe soal (khusus Menjodohkan gunakan dropdown/bottom-sheet, bukan drag-and-drop) (`FR-FE-22`).
- [x] Engine autosave lokal dengan antrean IndexedDB dan penanganan retry/offline otomatis (`FR-FE-30`, `FR-FE-32`).
- [x] Indikator status koneksi/sinkronisasi: *Tersimpan / Menyimpan... / Offline* (`FR-FE-33`).
- [x] Display timer sinkron dengan server + peringatan visual sisa 10 & 5 menit (`FR-FE-41`, `FR-FE-42`).
- [x] Blokir interaksi browser terlarang: klik kanan, copy, cut, paste, text select (`FR-FE-60`).
- [x] Modal konfirmasi submit buatan sendiri (menampilkan rekap status terjawab) (`FR-FE-70`).
- [x] Halaman selesai sederhana ("Ujian selesai") **tanpa skor/nilai** (`FR-FE-72`).

---

## Fase 2: Pengawasan, Kunci Sesi Realtime, & Koreksi Essay

### 2.1 Kunci Sesi (Zero Tolerance)
#### Backend
- [ ] Migration tabel `lock_event`.
- [ ] Endpoint `POST /api/v1/ujian/lock`: status peserta langsung menjadi `terkunci` (`FR-BE-70`).
- [ ] Penolakan simpan jawaban (`SESSION_LOCKED`) selama status peserta terkunci (`FR-BE-72`).
- [ ] Penegakan aturan: **waktu tetap berjalan** saat terkunci (tanpa penambahan durasi) (`FR-BE-74`).
- [ ] Endpoint buka kunci (unlock) dengan kewajiban mengisi alasan (min. 5 karakter) (`FR-BE-73`).
- [ ] Rekam histori `lock_event` (pemicu, waktu kunci, waktu buka, aktor, alasan).
- [ ] Penghitung akumulasi kunci per siswa & penanda ambang batas peringatan (`FR-BE-75`).

#### Frontend
- [ ] Event listener deteksi pelanggaran di client saat status `mengerjakan`: `visibilitychange`, `pagehide`, `blur`, `fullscreenchange` (`FR-FE-50`).
- [ ] Pengiriman sinyal lock seketika menggunakan `navigator.sendBeacon` atau `fetch keepalive` (`FR-FE-51`).
- [ ] Antrean event lock offline: kirim event lock tertunda lebih dulu sebelum jawaban (`FR-FE-52`).
- [ ] Layar overlay status terkunci ("Ujian terkunci, hubungi pengawas") dengan input dinonaktifkan (`FR-FE-54`).
- [ ] Pastikan timer tetap berjalan di layar saat terkunci (`FR-FE-44`).
- [ ] Cek status berkala ke server untuk menerima pembaruan unlock dari pengawas (`FR-FE-54`).

---

### 2.2 Realtime Monitoring & Dashboard Pengawas
#### Backend
- [ ] Channel WebSocket private `sesi.{sesiRuangId}` di Laravel Reverb (`FR-BE-100`).
- [ ] Event broadcasting: `PesertaStatusChanged`, `PesertaLocked`, `PesertaUnlocked`, `LockThresholdReached`, `PesertaProgress` (throttled).
- [ ] Endpoint monitoring sesi-ruang (daftar peserta, status, nomor terjawab, sisa waktu).
- [ ] Endpoint reset login perangkat siswa oleh Pengawas/Operator (`FR-BE-04`).
- [ ] Otorisasi ketat data monitoring: sembunyikan total konten soal, kunci, dan nilai dari Pengawas (`FR-BE-100`).

#### Frontend
- [ ] Halaman Dashboard Monitoring Pengawas (responsif HP/tablet untuk pengawas keliling) (`FR-FE-97`).
- [ ] Integrasi WebSocket `sesi.{id}` dengan fallback polling otomatis (`FR-FE-95`).
- [ ] Kartu status siswa realtime (belum login, menunggu, mengerjakan, terkunci, selesai) (`FR-FE-90`).
- [ ] Auto-pin siswa terkunci ke urutan teratas + notifikasi alert visual/audio (`FR-FE-91`).
- [ ] Modal Buka Kunci dengan input alasan wajib & opsi alasan cepat ("Layar mati", "Panggilan masuk", dll.) (`FR-FE-92`).
- [ ] Peringatan jika siswa melebihi ambang batas kunci (`FR-FE-93`).
- [ ] Aksi Reset Login siswa dari dashboard pengawas (`FR-FE-94`).

---

### 2.3 Koreksi Essay & Rekap Nilai Guru Mapel
#### Backend
- [ ] Endpoint daftar jawaban essay yang butuh koreksi per jadwal/kelas (`FR-BE-84`).
- [ ] Endpoint submit nilai essay per butir oleh guru mapel pengampu (skor 0 sampai bobot soal) (`FR-BE-84`).
- [ ] Rekalkulasi nilai akhir otomatis setelah seluruh jawaban essay terkoreksi (`FR-BE-84`).
- [ ] Endpoint rekapitulasi nilai untuk Guru Mapel, Operator, dan Superadmin (`FR-BE-90`).
- [ ] Otorisasi data nilai: batasi akses guru hanya untuk kelas dan mapel yang diajarkan.

#### Frontend
- [ ] Antarmuka antrean koreksi essay: menampilkan soal, kunci panduan, rubrik, dan jawaban siswa (`FR-FE-130`).
- [ ] Input skor essay dengan validasi batas maksimal skor sesuai bobot soal.
- [ ] Halaman Rekap Nilai dengan indikator status nilai: *Menunggu Koreksi Essay* vs *Final* (`FR-FE-131`).

---

## Fase 3: Pelaporan, Administrasi, & Hardening Produksi

### 3.1 Berita Acara, Cetak Dokumen, & Audit Log
#### Backend
- [ ] Migration tabel `berita_acara`, `log_aktivitas`.
- [ ] CRUD Berita Acara pelaksanaan ujian per sesi-ruang (`FR-BE-102`).
- [ ] Logging append-only aktivitas kritikal: login, lock, unlock, submit, ubah nilai essay, dll. (`FR-BE-101`).
- [ ] Generator dokumen PDF: Kartu Peserta Ujian, Daftar Hadir, Berita Acara, Rekap Nilai (`FR-BE-55`, `FR-BE-91`).
- [ ] Endpoint analisis butir soal: kalkulasi tingkat kesukaran ($p$) dan daya pembeda ($D$) (`FR-BE-86`).
- [ ] Skrip backup database otomatis terjadwal (`FR-BE-103`).

#### Frontend
- [ ] Form pengisian Berita Acara oleh pengawas ruangan sebelum menutup sesi.
- [ ] UI visualisasi Analisis Butir Soal untuk guru mapel (`FR-FE-132`).
- [ ] Halaman cetak / export PDF untuk kartu ujian, daftar hadir, dan berita acara (`FR-FE-133`).
- [ ] Halaman penelusuran Audit Log untuk Superadmin dengan filter aktor, waktu, dan aksi (`FR-FE-140`).

---

### 3.2 Uji Beban (Load Test 700 User) & Hardening Produksi
#### Backend & Infrastruktur
- [ ] Setup script load test k6 / JMeter dengan skenario riil 700 user (`NFR-BE-05`):
  - [ ] 700 login serentak.
  - [ ] 700 join token serempak.
  - [ ] Autosave berulang tiap 5–15 detik dengan payload delta.
  - [ ] 10–20% random lock & unlock events.
  - [ ] Submit massal di akhir waktu.
- [ ] Profiling query database: pastikan tidak ada query N+1 pada endpoint ujian & monitoring (`NFR-BE-03`).
- [ ] Benchmark latensi endpoint kritis p95 < 300 ms (`NFR-BE-02`).
- [ ] Konfigurasi workers queue Redis untuk pemrosesan penilaian dan auto-submit massal.
- [ ] Pengujian skenario pemulihan server (restart service saat ujian berjalan, verifikasi keutuhan data).

#### Frontend
- [ ] Audit bundle size: pastikan bundle awal siswa ≤ 200 KB gzip (`NFR-FE-01`).
- [ ] Pastikan dependensi berat (TipTap, parser Word/Excel) terisolasi lewat code splitting (`NFR-FE-02`).
- [ ] Uji performa halaman ujian pada perangkat HP low-end (emulasi CPU throttling & memori terbatas) (`NFR-FE-03`).
- [ ] Uji responsivitas offline di jaringan tidak stabil / 3G lambat.
- [ ] Uji kompatibilitas browser: Safari iOS, Chrome Android, Samsung Internet (`NFR-FE-10`).

