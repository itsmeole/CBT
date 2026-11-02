# Requirement Frontend: Website Ujian Sekolah (CBT)

> Sumber acuan: [`../ide.md`](../ide.md) dan kontrak API di [`../BE/BE.md`](../BE/BE.md).
> Aturan wajib untuk agent AI ada di [`Rules FE.md`](./Rules%20FE.md).
> Status: **Draft v1**.

---

## 1. Ringkasan

Frontend berupa SPA yang melayani 4 role. Halaman **siswa** wajib **mobile-first** karena ujian dikerjakan lewat HP masing-masing. Halaman staf (Superadmin, Operator, Guru) dirancang desktop-first tetapi tetap responsif, khususnya **dashboard pengawas** yang kemungkinan dibuka lewat HP/tablet.

**Prinsip utama**
- Server adalah sumber kebenaran untuk status, timer, kunci, dan nilai. FE hanya menampilkan dan melaporkan.
- Siswa tidak pernah melihat nilai, skor, kunci, atau pembahasan.
- Hemat kuota dan tahan sinyal lemah.

---

## 2. Stack (Default)

| Bagian | Pilihan |
|---|---|
| Framework | Vue 3 (Composition API) + Vite + TypeScript |
| Routing dan state | Vue Router, Pinia |
| Styling | Tailwind CSS |
| HTTP | Axios (interceptor auth dan error terpusat) |
| Realtime | Laravel Echo + Pusher JS (ke Laravel Reverb) |
| Rumus | KaTeX |
| Editor soal | TipTap (dengan ekstensi gambar, tabel, rumus LaTeX) |
| Penyimpanan lokal | IndexedDB (via `idb`/`localforage`) untuk antrean autosave |
| Test | Vitest (unit), Playwright (E2E, termasuk emulasi mobile) |

> Pergantian stack atau library utama harus disetujui pemilik proyek (lihat Rules FE).

---

## 3. Sitemap per Role

### 3.1 Umum
- `/login`
- Halaman error: 403, 404, "Akun sedang aktif di perangkat lain", "Sesi berakhir".

### 3.2 Siswa (mobile-first)
| Route | Halaman |
|---|---|
| `/ujian` | Daftar ujian hari ini + input token |
| `/ujian/tunggu` | Ruang tunggu (menunggu operator menekan Mulai) |
| `/ujian/kerjakan` | Halaman pengerjaan |
| `/ujian/terkunci` | Overlay/state terkunci (bagian dari halaman pengerjaan) |
| `/ujian/selesai` | "Ujian selesai" (tanpa nilai) |

### 3.3 Operator
- Dashboard ringkas: jadwal hari ini, sesi berjalan, jumlah peserta per status.
- Master data: kelas, siswa, guru, mapel, guru_mapel, ruang (+ impor/ekspor Excel).
- Bobot per tipe soal.
- Jadwal ujian, sesi-ruang, pemetaan peserta, token.
- Plotting pengawas.
- **Kontrol sesi: verifikasi kehadiran dan tombol Mulai** (per sesi-ruang dan per siswa).
- Monitoring semua sesi, buka kunci, reset login.
- Cetak: kartu peserta, daftar hadir, berita acara, rekap nilai.
- Nilai (semua mapel).

### 3.4 Guru
- **Sebagai guru mapel:** bank soal (daftar, editor, impor/ekspor Word), paket soal, koreksi essay, nilai dan analisis butir (hanya mapel/kelas yang diampu).
- **Sebagai pengawas:** daftar sesi yang ditugaskan, dashboard monitoring, buka kunci (dengan alasan), reset login, berita acara.
- Menu ditampilkan sesuai peran yang dimiliki pada tahun ajaran aktif.

### 3.5 Superadmin
- Semua halaman Operator dan Guru.
- User dan role, tahun ajaran/semester, pengaturan sistem (kebijakan login ganda: `tolak`/`kick`, ambang peringatan kunci), log aktivitas, backup.

---

## 4. Requirement Halaman Siswa

### 4.1 Login dan Token
- **FR-FE-01** Form login sederhana, tombol besar, tampilkan pesan dari kode error API (`ACCOUNT_IN_USE`, `RATE_LIMITED`, dsb.) dalam bahasa Indonesia yang jelas.
- **FR-FE-02** Input token: 6 karakter, otomatis huruf besar, keyboard tidak menebak/autocorrect (`autocapitalize="characters"`, `autocomplete="off"`, `spellcheck="false"`).
- **FR-FE-03** Sebelum masuk ujian, tampilkan **checklist persiapan HP**: mode jangan ganggu/pesawat + WiFi, baterai cukup, tutup aplikasi lain, peringatan bahwa keluar halaman akan mengunci ujian dan **waktu tetap berjalan**.

### 4.2 Ruang Tunggu
- **FR-FE-10** Tampilkan nama ujian, mapel, sesi, ruang, durasi, dan status "Menunggu operator memulai ujian".
- **FR-FE-11** Pindah ke halaman pengerjaan saat event `UjianDimulai` diterima, dengan fallback polling `GET /ujian/state` setiap 5 sampai 10 detik.
- **FR-FE-12** Deteksi keluar halaman **belum aktif** di ruang tunggu. Deteksi kunci baru aktif setelah status menjadi `mengerjakan`.

### 4.3 Halaman Pengerjaan
- **FR-FE-20** Layout mobile-first: header (mapel, timer, indikator simpan), area soal, navigasi bawah (Sebelumnya, nomor soal, Berikutnya). Tombol minimal 44×44 px.
- **FR-FE-21** Panel nomor soal ringkas (grid) dengan status: belum dijawab, sudah dijawab, ragu-ragu (penanda lokal + disimpan sebagai flag).
- **FR-FE-22** Render 5 tipe soal:

| Tipe | Komponen input |
|---|---|
| PG | Radio (satu pilihan), area sentuh selebar baris opsi |
| PG kompleks | Checkbox (banyak pilihan), dengan teks petunjuk "Pilih lebih dari satu jawaban" |
| Benar/Salah | Untuk tiap pernyataan, dua tombol Benar/Salah (tabel yang tetap terbaca di layar sempit) |
| Menjodohkan | Untuk tiap premis, pilihan respons lewat dropdown/bottom sheet (bukan drag and drop, demi kemudahan di HP) |
| Essay | Textarea dengan penghitung karakter. Tempel (paste) dinonaktifkan |

- **FR-FE-23** Render konten HTML soal dari server yang sudah disanitasi, rumus dengan KaTeX, gambar `loading="lazy"` dan bisa diperbesar (tap untuk zoom), audio dengan pemutar sederhana.
- **FR-FE-24** Urutan soal dan opsi **mengikuti urutan dari server** apa adanya. FE tidak mengacak ulang.

### 4.4 Autosave dan Recovery
- **FR-FE-30** Setiap perubahan jawaban langsung masuk **antrean lokal (IndexedDB)** dengan `client_seq` yang naik, lalu dikirim ke server. Untuk essay, kirim setelah jeda ketik (debounce ±1,5 detik) dan saat pindah soal.
- **FR-FE-31** Kirim **hanya jawaban yang berubah**, dalam batch jika ada beberapa yang tertunda.
- **FR-FE-32** Jika gagal/offline: simpan di antrean, coba ulang dengan backoff (1, 2, 4, ... maks 30 detik), dan kirim segera saat event `online`.
- **FR-FE-33** Indikator status simpan selalu terlihat: **Tersimpan** / **Menyimpan…** / **Offline, jawaban disimpan di perangkat**.
- **FR-FE-34** Saat halaman dibuka ulang: ambil state dari server, lalu gabungkan dengan antrean lokal yang `client_seq`-nya lebih baru, lalu kirim ulang.
- **FR-FE-35** Respons autosave dengan kode `SESSION_LOCKED`, `TIME_UP`, atau `ALREADY_SUBMITTED` langsung mengubah tampilan ke state yang sesuai.

### 4.5 Timer
- **FR-FE-40** Sinkronkan offset waktu dengan `GET /time` saat masuk dan setiap ±60 detik.
- **FR-FE-41** Sisa waktu = `waktu_selesai` (dari server) − (waktu perangkat + offset). Timer hanya **tampilan**. FE tidak pernah mengirim sisa waktu ke server.
- **FR-FE-42** Peringatan visual saat sisa 10 menit dan 5 menit.
- **FR-FE-43** Saat timer tampilan habis: kirim antrean yang tersisa, panggil `GET /ujian/state`, lalu tampilkan halaman selesai sesuai status server.
- **FR-FE-44** Timer tetap terlihat berjalan saat layar terkunci (sesuai keputusan, waktu tidak berhenti).

### 4.6 Deteksi Keluar Halaman dan Kunci (tanpa toleransi)
- **FR-FE-50** Saat status `mengerjakan`, pasang listener: `visibilitychange` (hidden), `pagehide`, `blur` pada window, `fullscreenchange` (keluar fullscreen, hanya di perangkat yang mendukung).
- **FR-FE-51** Saat terpicu: **langsung** tampilkan overlay terkunci, nonaktifkan semua input, dan kirim `POST /ujian/lock` (gunakan `navigator.sendBeacon` atau `fetch` dengan `keepalive` untuk `pagehide`). Tidak ada debounce atau toleransi.
- **FR-FE-52** Jika kiriman lock gagal (offline), simpan event lock di penyimpanan lokal dan kirim saat koneksi pulih, **sebelum** mengirim jawaban apa pun.
- **FR-FE-53** Saat halaman kembali terlihat atau dibuka ulang, selalu cek `GET /ujian/state`. Jika server berstatus `terkunci`, tampilkan overlay terkunci.
- **FR-FE-54** Overlay terkunci: teks "Ujian terkunci, hubungi pengawas", timer tetap tampil, tanpa tombol buka sendiri. Hilang hanya saat event `SesiUnlocked` diterima **dan** dikonfirmasi oleh `GET /ujian/state` (fallback polling setiap 5 detik).
- **FR-FE-55** Fullscreen diminta saat mulai mengerjakan **jika didukung** (Android Chrome, desktop). Di iPhone/Safari, deteksi bertumpu pada `visibilitychange`, `blur`, dan `pagehide`.
- **FR-FE-56** Reload atau tutup tab dianggap keluar halaman dan ikut mengunci.

### 4.7 Anti Contek (sebatas kemampuan browser)
- **FR-FE-60** Blokir menu klik kanan/tekan lama (`contextmenu`), `copy`, `cut`, `paste`, `selectstart`, dan `dragstart` di halaman pengerjaan. Gunakan `user-select: none` untuk konten soal.
- **FR-FE-61** Tangkap tombol PrintScreen di desktop (hapus clipboard + catat log). Akui bahwa screenshot di HP tidak bisa dicegah dari web.
- **FR-FE-62** Jangan menyimpan konten soal ke `localStorage`/cache permanen. Konten soal hanya di memori. Yang disimpan lokal hanya antrean jawaban dan event lock.

### 4.8 Submit dan Selesai
- **FR-FE-70** Tombol Selesai membuka modal konfirmasi buatan sendiri (bukan `confirm()` bawaan browser) dengan ringkasan: jumlah terjawab, belum terjawab, ragu-ragu.
- **FR-FE-71** Sebelum submit, pastikan antrean autosave kosong. Jika masih offline, tampilkan peringatan dan tahan submit sampai tersinkron atau waktu habis.
- **FR-FE-72** Halaman selesai hanya berisi "Ujian selesai. Terima kasih." **Tanpa nilai, skor, kunci, atau pembahasan.** Lepas semua listener kunci dan bersihkan antrean lokal ujian tersebut.

---

## 5. Requirement Halaman Staf

### 5.1 Kontrol Sesi (Operator/Superadmin saja)
- **FR-FE-80** Daftar peserta sesi-ruang dengan status (`belum_login`, `menunggu`, `mengerjakan`, `terkunci`, `selesai`).
- **FR-FE-81** Centang kehadiran per siswa (hadir/tidak hadir), aksi massal "tandai semua yang menunggu sebagai hadir".
- **FR-FE-82** Tombol **Mulai** per sesi-ruang (untuk siswa terverifikasi) dan per siswa (untuk yang terlambat), dengan modal konfirmasi.
- **FR-FE-83** Komponen dan route ini **tidak ditampilkan** untuk guru pengawas. Meski begitu, penegakan tetap di server.

### 5.2 Dashboard Monitoring (Pengawas, Operator, Superadmin)
- **FR-FE-90** Kartu/tabel per siswa: nama, NIS, kelas, status (warna + ikon + teks, bukan warna saja), progres terjawab, jumlah kunci, sisa waktu.
- **FR-FE-91** Siswa terkunci naik ke atas daftar, disertai **notifikasi realtime** (suara opsional + getar di HP jika didukung + badge).
- **FR-FE-92** Tombol **Buka kunci** membuka modal dengan kolom **alasan wajib** (minimal 5 karakter) dan pilihan alasan cepat ("Notifikasi masuk", "Telepon masuk", "Layar mati", "Tidak sengaja"), untuk mempercepat proses karena waktu siswa tetap berjalan.
- **FR-FE-93** Tampilkan peringatan untuk siswa yang mencapai ambang jumlah kunci (`LockThresholdReached`), dengan pintasan ke form berita acara.
- **FR-FE-94** Reset login per siswa dengan konfirmasi.
- **FR-FE-95** Berlangganan channel `sesi.{id}`, dengan fallback polling monitoring setiap 10 detik jika WebSocket putus, dan indikator status koneksi realtime.
- **FR-FE-96** Pengawas **tidak** pernah dimuat konten soal, jawaban, atau nilai.
- **FR-FE-97** Responsif untuk HP/tablet (pengawas berkeliling ruangan).

### 5.3 Bank Soal dan Paket (Guru mapel)
- **FR-FE-100** Daftar soal dengan filter mapel, tipe, kata kunci, dan pratinjau tampilan seperti yang dilihat siswa.
- **FR-FE-101** Editor soal per tipe (TipTap): teks kaya terbatas, gambar, tabel, rumus LaTeX dengan pratinjau KaTeX, unggah audio. Form kunci menyesuaikan tipe (radio untuk PG, checkbox untuk PG kompleks minimal 2 benar, B/S per pernyataan, pasangan untuk menjodohkan, kunci + rubrik untuk essay).
- **FR-FE-102** Validasi di form sesuai aturan BE (jumlah opsi, kunci wajib, dsb.), tetap mengandalkan validasi server.
- **FR-FE-103** Soal yang terkunci karena sudah dipakai jadwal berjalan ditampilkan read-only dengan keterangan.
- **FR-FE-104** Penyusun paket: pilih soal dari bank, atur urutan, ringkasan jumlah per tipe.

### 5.4 Impor/Ekspor Soal lewat Word (Guru mapel)
- **FR-FE-110** Hanya menerima file `.docx`. Tidak ada opsi impor soal dari Excel.
- **FR-FE-111** Wizard 3 langkah: (1) unduh template + unggah file, (2) **pratinjau** soal hasil parsing (dirender seperti tampilan siswa) + **daftar error per soal**, (3) konfirmasi simpan. Tombol simpan nonaktif selama ada error.
- **FR-FE-112** Pengingat bahwa audio ditambahkan lewat editor setelah impor.
- **FR-FE-113** Tombol ekspor bank soal/paket ke Word.

### 5.5 Master Data dan Impor Excel (Operator/Superadmin)
- **FR-FE-120** Tabel data dengan pencarian, filter, paginasi server-side.
- **FR-FE-121** Wizard impor Excel: unduh template, unggah, pratinjau + error per baris (nomor baris, kolom, pesan), konfirmasi.
- **FR-FE-122** Ekspor Excel untuk siswa, master data, dan rekap nilai.

### 5.6 Penilaian dan Laporan
- **FR-FE-130** Koreksi essay: tampilkan soal, kunci, rubrik, jawaban siswa (identitas dapat disembunyikan untuk objektivitas), input skor 0 sampai bobot, catatan, navigasi ke jawaban berikutnya.
- **FR-FE-131** Rekap nilai dengan label "Menunggu koreksi essay" untuk nilai sementara.
- **FR-FE-132** Analisis butir: tabel tingkat kesukaran dan daya pembeda per soal.
- **FR-FE-133** Cetak/unduh PDF: kartu peserta, daftar hadir, berita acara, rekap nilai.

### 5.7 Superadmin
- **FR-FE-140** Kelola user dan role, tahun ajaran/semester, pengaturan sistem, log aktivitas (filter aktor, aksi, tanggal), backup manual.

---

## 6. Requirement Non-Fungsional

### 6.1 Kinerja dan Hemat Kuota
- **NFR-FE-01** Bundle halaman siswa dipisah dari halaman staf (code splitting per role). Target JS awal halaman siswa ≤ 200 KB gzip.
- **NFR-FE-02** Editor TipTap, pustaka Excel/Word, dan grafik **tidak** dimuat di halaman siswa.
- **NFR-FE-03** Halaman pengerjaan tetap lancar di HP kelas bawah (RAM 2 sampai 3 GB) dan jaringan 3G lambat. Uji dengan throttling.
- **NFR-FE-04** Gambar lazy load, KaTeX dirender sekali per soal lalu di-cache di memori.

### 6.2 Kompatibilitas
- **NFR-FE-10** Dukungan: Chrome Android (2 versi utama terakhir), Safari iOS 15+, Samsung Internet, Chrome/Edge/Firefox desktop terbaru.
- **NFR-FE-11** Layar 360 px sampai desktop. Siswa: orientasi potret diutamakan.

### 6.3 UX dan Aksesibilitas
- **NFR-FE-20** Seluruh teks antarmuka berbahasa Indonesia.
- **NFR-FE-21** Kontras teks memenuhi WCAG AA, ukuran font dasar halaman siswa minimal 16 px.
- **NFR-FE-22** Status tidak hanya dibedakan dengan warna (gunakan ikon/teks).
- **NFR-FE-23** Setiap aksi berisiko (Mulai, buka kunci, reset login, submit, commit impor) memakai modal konfirmasi buatan sendiri.

### 6.4 Keamanan
- **NFR-FE-30** Token auth disimpan sesuai mekanisme Sanctum (cookie `HttpOnly`) atau di memori. Jangan simpan token di `localStorage`.
- **NFR-FE-31** Konten HTML dari server tetap disanitasi ulang di client (misalnya DOMPurify) sebelum `v-html`.
- **NFR-FE-32** Penyembunyian menu per role hanya untuk UX. Semua keputusan akses tetap di server.

---

## 7. Testing dan Kriteria Penerimaan

**Unit (Vitest):** antrean autosave (urutan `client_seq`, retry, merge saat recovery), perhitungan sisa waktu dengan offset, komponen 5 tipe soal, guard route per role.

**E2E (Playwright, emulasi Pixel dan iPhone):**
- Login, input token, ruang tunggu, Mulai oleh operator, mengerjakan, submit, halaman selesai tanpa nilai.
- Pindah tab/`visibilitychange` langsung mengunci, input nonaktif, pengawas menerima notifikasi, buka kunci dengan alasan, siswa lanjut, timer tidak bertambah.
- Offline saat menjawab, jawaban tersimpan lokal, online kembali, tersinkron.
- Reload di tengah ujian: terkunci, setelah dibuka jawaban dan urutan soal tetap sama.
- Waktu habis saat terkunci: siswa langsung masuk halaman selesai.
- Guru pengawas tidak melihat menu/komponen Mulai dan verifikasi kehadiran.
- Impor Word dengan file berisi error: tombol simpan nonaktif, error per soal tampil.

**Definition of Done per halaman:** responsif di 360 px, state loading/kosong/error ditangani, teks bahasa Indonesia, test terkait lulus.

---

## 8. Tahapan (mengikuti ide.md)

| Tahap | Cakupan frontend |
|---|---|
| **1. MVP** | Login 4 role, master data + wizard impor Excel, bank soal + editor + wizard impor Word, paket soal, bobot, jadwal/sesi/token, kontrol sesi operator (kehadiran + Mulai), halaman ujian siswa (5 tipe, autosave, timer, submit) |
| **2. Pengawasan dan penilaian** | Deteksi dan overlay kunci, dashboard monitoring realtime, buka kunci dengan alasan, reset login, koreksi essay, rekap nilai |
| **3. Penyempurnaan** | Log aktivitas, berita acara, ekspor dan cetak, analisis butir, ujian susulan, penyesuaian SEB/wrapper, optimasi performa |
