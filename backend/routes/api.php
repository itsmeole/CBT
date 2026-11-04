<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdministrasiUjianController;
use App\Http\Controllers\AnalisisButirController;
use App\Http\Controllers\BankSoalController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\KoreksiEssayController;
use App\Http\Controllers\LaporanPdfController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\OperatorKontrolController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\PengawasanController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\WordSoalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Sinkronisasi Waktu Server (FR-BE-06)
    Route::get('time', fn () => response()->json(['time' => (int) (microtime(true) * 1000)]));

    // Autentikasi
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Route Berautentikasi
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::middleware('role:operator,superadmin,guru')->group(function () {
            Route::post('auth/reset-login/{userId}', [PengawasanController::class, 'resetLogin']);
            Route::get('sesi-ruang/{id}/monitoring', [PengawasanController::class, 'monitoring']);
            Route::post('peserta/{id}/unlock', [PengawasanController::class, 'unlock']);
            Route::get('sesi-ruang/{id}/berita-acara', [AdministrasiUjianController::class, 'beritaAcara']);
            Route::post('sesi-ruang/{id}/berita-acara', [AdministrasiUjianController::class, 'simpanBeritaAcara']);
            Route::put('sesi-ruang/{id}/berita-acara', [AdministrasiUjianController::class, 'simpanBeritaAcara']);
            Route::post('laporan-pdf/{jenis}', [LaporanPdfController::class, 'store']);
            Route::get('laporan-pdf/{laporanId}', [LaporanPdfController::class, 'show'])->whereNumber('laporanId');
        });

        Route::middleware('role:superadmin')->group(function () {
            Route::get('log-aktivitas', [AdministrasiUjianController::class, 'logAktivitas']);
        });

        Route::middleware('role:operator,superadmin')->group(function () {
            Route::get('lock-threshold', [PengawasanController::class, 'showAmbangKunci']);
            Route::put('lock-threshold', [PengawasanController::class, 'updateAmbangKunci']);
        });

        Route::middleware('role:guru,superadmin')->group(function () {
            Route::get('koreksi/essay', [KoreksiEssayController::class, 'index']);
            Route::put('koreksi/essay/{jawabanId}', [KoreksiEssayController::class, 'update']);
        });

        Route::middleware('role:guru,operator,superadmin')->group(function () {
            Route::get('nilai', [NilaiController::class, 'index']);
            Route::get('analisis-butir/{jadwalId}', [AnalisisButirController::class, 'show']);
        });

        Route::middleware('role:superadmin')->group(function () {
            Route::get('settings', [PengaturanController::class, 'show']);
            Route::put('settings', [PengaturanController::class, 'update']);
            Route::get('users', [MasterDataController::class, 'indexUsers']);
            Route::post('users', [MasterDataController::class, 'storeUser']);
            Route::put('users/{id}', [MasterDataController::class, 'updateUser']);
            Route::delete('users/{id}', [MasterDataController::class, 'destroyUser']);
        });

        // ========================================================
        // 1. MASTER DATA (Superadmin & Operator)
        // ========================================================
        Route::middleware('role:superadmin,operator')->group(function () {
            // Tahun Ajaran
            Route::get('tahun-ajaran', [MasterDataController::class, 'getTahunAjaran']);
            Route::post('tahun-ajaran', [MasterDataController::class, 'storeTahunAjaran']);
            Route::put('tahun-ajaran/{id}', [MasterDataController::class, 'updateTahunAjaran']);
            Route::delete('tahun-ajaran/{id}', [MasterDataController::class, 'destroyTahunAjaran']);

            // Kelas
            Route::get('kelas', [MasterDataController::class, 'getKelas']);
            Route::post('kelas', [MasterDataController::class, 'storeKelas']);
            Route::put('kelas/{id}', [MasterDataController::class, 'updateKelas']);
            Route::delete('kelas/{id}', [MasterDataController::class, 'destroyKelas']);

            // Siswa
            Route::get('siswa', [MasterDataController::class, 'getSiswa']);
            Route::post('siswa', [MasterDataController::class, 'storeSiswa']);
            Route::put('siswa/{id}', [MasterDataController::class, 'updateSiswa']);
            Route::delete('siswa/{id}', [MasterDataController::class, 'destroySiswa']);

            // Guru
            Route::get('guru', [MasterDataController::class, 'getGuru']);
            Route::post('guru', [MasterDataController::class, 'storeGuru']);
            Route::put('guru/{id}', [MasterDataController::class, 'updateGuru']);
            Route::delete('guru/{id}', [MasterDataController::class, 'destroyGuru']);

            // Mapel
            Route::get('mapel', [MasterDataController::class, 'getMapel']);
            Route::post('mapel', [MasterDataController::class, 'storeMapel']);
            Route::put('mapel/{id}', [MasterDataController::class, 'updateMapel']);
            Route::delete('mapel/{id}', [MasterDataController::class, 'destroyMapel']);

            // Guru Mapel
            Route::get('guru-mapel', [MasterDataController::class, 'getGuruMapel']);
            Route::post('guru-mapel', [MasterDataController::class, 'storeGuruMapel']);
            Route::delete('guru-mapel/{id}', [MasterDataController::class, 'destroyGuruMapel']);

            // Ruang
            Route::get('ruang', [MasterDataController::class, 'getRuang']);
            Route::post('ruang', [MasterDataController::class, 'storeRuang']);
            Route::put('ruang/{id}', [MasterDataController::class, 'updateRuang']);
            Route::delete('ruang/{id}', [MasterDataController::class, 'destroyRuang']);

            // Impor / Ekspor Excel Master Data
            Route::get('templates/excel/{jenis}', [ExcelController::class, 'template']);
            Route::post('import/excel/{jenis}/preview', [ExcelController::class, 'preview']);
            Route::post('import/excel/{jenis}/commit', [ExcelController::class, 'commit']);

            // Bobot Tipe Soal
            Route::get('bobot-tipe', [JadwalController::class, 'getBobotTipe']);
            Route::put('bobot-tipe', [JadwalController::class, 'updateBobotTipe']);

            // Jadwal Ujian & Sesi Ruang
            Route::get('jadwal', [JadwalController::class, 'getJadwal']);
            Route::post('jadwal', [JadwalController::class, 'storeJadwal']);
            Route::post('sesi-ruang', [JadwalController::class, 'storeSesiRuang']);
            Route::post('sesi-ruang/{id}/pengawas', [JadwalController::class, 'plotPengawas']);
            Route::post('sesi-ruang/{id}/token/generate', [JadwalController::class, 'generateToken']);

            // KONTROL SESI OPERATOR (HANYA OPERATOR & SUPERADMIN, 403 UNTUK GURU!)
            Route::get('sesi-ruang/{id}/peserta', [OperatorKontrolController::class, 'getPeserta']);
            Route::post('sesi-ruang/{id}/kehadiran', [OperatorKontrolController::class, 'setKehadiran']);
            Route::post('sesi-ruang/{id}/mulai', [OperatorKontrolController::class, 'mulaiSesi']);
            Route::post('peserta/{id}/mulai', [OperatorKontrolController::class, 'mulaiPeserta']);
        });

        // ========================================================
        // 2. BANK SOAL & WORD (.docx) (Guru & Superadmin)
        // ========================================================
        Route::middleware('role:guru,superadmin')->group(function () {
            // CRUD Bank Soal
            Route::get('bank-soal', [BankSoalController::class, 'index']);
            Route::get('bank-soal/{id}', [BankSoalController::class, 'show']);
            Route::post('bank-soal', [BankSoalController::class, 'store']);
            Route::put('bank-soal/{id}', [BankSoalController::class, 'update']);
            Route::delete('bank-soal/{id}', [BankSoalController::class, 'destroy']);
            Route::post('media', [BankSoalController::class, 'uploadMedia']);

            // Impor / Ekspor Word (.docx)
            Route::get('templates/word', [WordSoalController::class, 'template']);
            Route::post('import/word/preview', [WordSoalController::class, 'preview']);
            Route::post('import/word/commit', [WordSoalController::class, 'commit']);
            Route::get('export/word/bank-soal', [WordSoalController::class, 'export']);

            // Paket Soal
            Route::get('paket-soal', [JadwalController::class, 'getPaketSoal']);
            Route::post('paket-soal', [JadwalController::class, 'storePaketSoal']);
            Route::delete('paket-soal/{id}', [JadwalController::class, 'destroyPaketSoal']);
        });

        // ========================================================
        // 3. PENGERJAAN UJIAN SISWA (Mobile-First) (Role Siswa)
        // ========================================================
        Route::middleware('role:siswa')->group(function () {
            Route::post('ujian/join', [UjianController::class, 'join']);
            Route::get('ujian/state', [UjianController::class, 'state']);
            Route::get('ujian/soal', [UjianController::class, 'getSoal']);
            Route::put('ujian/jawaban', [UjianController::class, 'autosave']);
            Route::post('ujian/lock', [PengawasanController::class, 'lock'])->middleware('throttle:exam-lock');
            Route::post('ujian/submit', [UjianController::class, 'submit']);
        });
    });
});
