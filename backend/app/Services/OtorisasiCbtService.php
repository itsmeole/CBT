<?php

namespace App\Services;

use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\PengawasSesi;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use App\Models\User;

class OtorisasiCbtService
{
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

        return PesertaUjian::query()
            ->whereIn('status', ['menunggu', 'mengerjakan', 'terkunci'])
            ->whereHas('siswa', fn ($query) => $query->where('user_id', $target->id))
            ->whereHas('sesiRuang.pengawasSesi', fn ($query) => $query->where('guru_id', $actor->guru->id))
            ->exists();
    }
}
