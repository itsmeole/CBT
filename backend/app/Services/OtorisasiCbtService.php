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
    public function bolehKelolaMapel(User $user, int $mapelId): bool
    {
        if (in_array($user->role, ['superadmin', 'operator', 'guru'], true)) {
            return true;
        }

        return false;
    }

    public function bolehAwasiSesi(User $user, int $sesiRuangId): bool
    {
        if (in_array($user->role, ['operator', 'superadmin', 'guru'], true)) {
            return SesiRuang::whereKey($sesiRuangId)->exists();
        }

        return false;
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
        if (in_array($actor->role, ['operator', 'superadmin', 'guru'], true)) {
            return $target->role === 'siswa';
        }

        return false;
    }
}
