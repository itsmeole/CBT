<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\PengawasSesi;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OtorisasiCbtService
{
    public function bolehKelolaMapel(User $user, int $mapelId): bool
    {
        return $user->role === 'superadmin'
            || ($user->role === 'guru'
                && $user->guru
                && GuruMapel::query()
                    ->where('guru_id', $user->guru->id)
                    ->where('mapel_id', $mapelId)
                    ->exists());
    }

    /**
     * Pemilik soal baru yang dibuat Operator/Superadmin (keduanya tidak punya data guru): guru yang dipilih
     * secara eksplisit, atau guru pengampu mapel tersebut.
     */
    public function guruPemilikSoal(?int $guruIdDiminta, int $mapelId): int
    {
        if ($guruIdDiminta !== null) {
            if (! Guru::whereKey($guruIdDiminta)->exists()) {
                throw ValidationException::withMessages(['guru_id' => 'Guru tidak ditemukan.']);
            }

            return $guruIdDiminta;
        }

        $guruId = GuruMapel::query()->where('mapel_id', $mapelId)->orderBy('id')->value('guru_id');
        if (! $guruId) {
            throw ValidationException::withMessages(['mapel_id' => 'Mapel ini belum memiliki guru pengampu. Tetapkan pengampu di Master Data terlebih dahulu.']);
        }

        return (int) $guruId;
    }

    public function bolehAwasiSesi(User $user, int $sesiRuangId): bool
    {
        if (in_array($user->role, ['operator', 'superadmin'], true)) {
            return SesiRuang::whereKey($sesiRuangId)->exists();
        }

        return $user->role === 'guru'
            && $user->guru
            && PengawasSesi::query()
                ->where('sesi_ruang_id', $sesiRuangId)
                ->where('guru_id', $user->guru->id)
                ->exists();
    }

    public function bolehAksesMapelKelas(User $user, JadwalUjian $jadwal, ?int $kelasId = null): bool
    {
        if ($user->role === 'superadmin') {
            return true;
        }

        if ($user->role !== 'guru' || ! $user->guru) {
            return false;
        }

        return GuruMapel::query()
            ->where('guru_id', $user->guru->id)
            ->where('mapel_id', $jadwal->mapel_id)
            ->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id)
            ->when($kelasId !== null, fn ($query) => $query->where('kelas_id', $kelasId))
            ->exists();
    }

    public function bolehResetSiswa(User $actor, User $target): bool
    {
        if (in_array($actor->role, ['operator', 'superadmin'], true)) {
            return $target->role === 'siswa';
        }

        if ($actor->role !== 'guru' || ! $actor->guru || $target->role !== 'siswa') {
            return false;
        }

        // Pengawas boleh mereset login siswa di sesinya selama ujiannya belum selesai (termasuk yang belum masuk
        // ruang tunggu, mis. akun tertahan setelah refresh atau berpindah perangkat).
        return PesertaUjian::query()
            ->whereIn('status', ['belum_login', 'menunggu', 'mengerjakan', 'terkunci'])
            ->whereHas('siswa', fn ($query) => $query->where('user_id', $target->id))
            ->whereHas('sesiRuang.pengawasSesi', fn ($query) => $query->where('guru_id', $actor->guru->id))
            ->exists();
    }
}
