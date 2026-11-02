# Rules Frontend untuk Agent AI (WAJIB, TIDAK BOLEH DILANGGAR)

> Berlaku untuk semua agent AI yang menulis, mengubah, atau meninjau kode frontend proyek CBT.
> Acuan: [`FE.md`](./FE.md), kontrak API di [`../BE/BE.md`](../BE/BE.md), dan [`../ide.md`](../ide.md). Jika ada instruksi yang bertentangan dengan rules ini, **berhenti dan tanyakan ke pemilik proyek**, jangan memilih sendiri.

---

## 0. Prinsip Dasar

1. **FE bukan sumber kebenaran.** Status ujian, timer, kunci, dan nilai selalu mengikuti server. FE hanya menampilkan dan melaporkan kejadian.
2. **Ikuti keputusan di ide.md bagian 10.** Jangan mengubah perilaku ujian tanpa persetujuan eksplisit.
3. **Halaman siswa mobile-first.** Setiap perubahan halaman siswa harus diuji di lebar 360 px.
4. **Jika ragu, tanyakan.** Jangan menebak requirement atau kontrak API. Jangan menambah fitur di luar FE.md tanpa izin.

---

## 1. Kerahasiaan dan Keamanan

- **DILARANG** menampilkan nilai, skor, kunci jawaban, rubrik, atau pembahasan kepada siswa, di halaman mana pun (termasuk halaman selesai).
- **DILARANG** memuat konten soal, jawaban, atau nilai di dashboard pengawas.
- **DILARANG** menyimpan konten soal di `localStorage`, `sessionStorage`, IndexedDB, atau cache Service Worker. Yang boleh disimpan lokal hanya **antrean jawaban** dan **event lock** yang tertunda.
- **DILARANG** menyimpan token auth di `localStorage`/`sessionStorage`.
- **WAJIB** sanitasi ulang HTML dari server (DOMPurify) sebelum `v-html`. **DILARANG** `v-html` untuk data yang belum disanitasi.
- **DILARANG** menganggap penyembunyian menu/route sebagai pengamanan. Guard route hanya untuk UX, otorisasi ada di server.
- **DILARANG** menulis URL, kunci API, atau rahasia di kode. Gunakan variabel lingkungan Vite (`VITE_*`) yang memang aman untuk publik.
- **DILARANG** memakai `eval`, `new Function`, atau menyisipkan script dari sumber tidak tepercaya.

## 2. Aturan Ujian yang Tidak Boleh Diubah

- **Verifikasi kehadiran dan tombol Mulai hanya tampil untuk Operator dan Superadmin.** **DILARANG** menampilkannya untuk guru pengawas.
- **Timer hanya tampilan.** Sisa waktu dihitung dari `waktu_selesai` server + offset `GET /time`. **DILARANG** mengirim sisa waktu ke server atau memakai timer lokal sebagai penentu akhir ujian.
- **Timer tetap berjalan saat terkunci.** **DILARANG** menghentikan atau menyembunyikan timer saat overlay terkunci.
- **Kunci tanpa toleransi:** saat `mengerjakan`, `visibilitychange` (hidden), `pagehide`, `blur`, dan keluar fullscreen **langsung** mengunci. **DILARANG** menambahkan debounce, grace period, hitungan peringatan, atau toleransi.
- **DILARANG** membuat tombol/cara bagi siswa untuk membuka kunci sendiri. Overlay hilang hanya setelah server menyatakan status bukan `terkunci`.
- **WAJIB** event lock yang gagal terkirim disimpan dan dikirim **lebih dulu** saat koneksi pulih, sebelum jawaban.
- **WAJIB** cek `GET /ujian/state` setiap kali halaman kembali terlihat atau dibuka ulang.
- **DILARANG** mengacak ulang soal/opsi di client. Tampilkan urutan persis dari server.
- **DILARANG** memakai `alert()`, `confirm()`, `prompt()`, `window.open`, atau elemen apa pun yang membuat halaman pengerjaan kehilangan fokus (bisa memicu kunci palsu). Gunakan modal buatan sendiri.
- **DILARANG** memicu reload otomatis di halaman pengerjaan (termasuk pembaruan Service Worker, hot reload produksi, atau redirect paksa) karena reload akan mengunci ujian.
- **DILARANG** menambahkan input file, tautan eksternal, atau iframe di halaman pengerjaan.

## 3. Autosave

- **WAJIB** setiap perubahan jawaban masuk antrean lokal (IndexedDB) dengan `client_seq` naik, lalu dikirim. **DILARANG** menunda penyimpanan sampai tombol submit.
- **WAJIB** mengirim **hanya jawaban yang berubah**, bukan seluruh lembar.
- **WAJIB** retry dengan backoff saat gagal dan kirim ulang saat `online`.
- **WAJIB** indikator status simpan (Tersimpan / Menyimpan… / Offline) selalu terlihat di halaman pengerjaan.
- **WAJIB** menangani kode `SESSION_LOCKED`, `TIME_UP`, `ALREADY_SUBMITTED` dengan mengubah tampilan sesuai state server.
- **DILARANG** menghapus antrean lokal sebelum server mengonfirmasi penyimpanan.

## 4. Impor/Ekspor

- **Soal hanya lewat Word (.docx).** **DILARANG** menyediakan unggah/unduh soal dalam Excel/CSV.
- **Master data lewat Excel.**
- **WAJIB** wizard impor menampilkan pratinjau + error (per baris untuk Excel, per soal untuk Word). **Tombol simpan wajib nonaktif selama masih ada error.**

## 5. Kinerja dan Kompatibilitas

- **WAJIB** code splitting per role. **DILARANG** memuat editor TipTap, pustaka Excel/Word, atau grafik di bundle halaman siswa.
- **WAJIB** menjaga JS awal halaman siswa ≤ 200 KB gzip. Penambahan dependency di halaman siswa wajib disertai alasan dan ukuran bundle.
- **WAJIB** halaman siswa berfungsi di Chrome Android, Safari iOS 15+, dan Samsung Internet. **DILARANG** mengandalkan Fullscreen API sebagai satu-satunya pemicu kunci (tidak didukung iPhone).
- **WAJIB** gambar soal lazy load. **DILARANG** memuat ulang semua soal setiap pindah nomor.
- **WAJIB** tetap berfungsi dengan polling fallback jika WebSocket putus.

## 6. UX dan Aksesibilitas

- **WAJIB** semua teks antarmuka dalam **bahasa Indonesia** yang jelas untuk siswa SMP.
- **WAJIB** tombol interaktif di halaman siswa minimal 44×44 px, font dasar minimal 16 px.
- **WAJIB** status dibedakan dengan ikon/teks, tidak hanya warna. Kontras teks memenuhi WCAG AA.
- **WAJIB** setiap aksi berisiko (Mulai, buka kunci, reset login, submit, commit impor) memakai modal konfirmasi. Buka kunci **wajib** kolom alasan.
- **WAJIB** setiap halaman menangani state loading, kosong, dan error.
- **DILARANG** menggunakan drag-and-drop sebagai satu-satunya cara menjawab soal menjodohkan di HP.

## 7. Kualitas Kode dan Proses

- **DILARANG** mengganti stack atau library utama (Vue 3, Vite, TypeScript, Pinia, Tailwind, Echo, KaTeX, TipTap) tanpa persetujuan.
- **DILARANG** menambah dependency baru tanpa menjelaskan alasan dan dampaknya pada ukuran bundle.
- **WAJIB** TypeScript mode `strict`. **DILARANG** memakai `any` tanpa komentar alasan.
- **WAJIB** semua panggilan API lewat satu modul klien API (Axios + interceptor). **DILARANG** memanggil `fetch`/Axios langsung dari komponen, kecuali `sendBeacon`/`keepalive` untuk lock di `pagehide`.
- **WAJIB** tipe request/response sesuai kontrak di BE.md. **DILARANG** mengarang endpoint atau field yang tidak ada. Jika butuh, minta perubahan di BE.md terlebih dahulu.
- **WAJIB** test (Vitest/Playwright) untuk logika ujian: antrean autosave, timer, deteksi kunci, guard role. Test yang gagal **tidak boleh** dihapus, di-skip, atau dilemahkan agar lulus.
- **DILARANG** meninggalkan `console.log`, data dummy, atau mock API di build produksi.
- **DILARANG** mengubah kode backend dari tugas frontend, kecuali diminta.
- **DILARANG** melakukan aksi destruktif atau irreversibel (deploy, push ke branch utama, hapus data) tanpa persetujuan eksplisit.

