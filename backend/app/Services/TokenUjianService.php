<?php

namespace App\Services;

use App\Models\SesiRuang;
use App\Models\TokenUjian;
use App\Models\User;

class TokenUjianService
{
    /**
     * Buat token aktif untuk sesi ruang.
     */
    public function buat(SesiRuang $sesi, User $pembuat): TokenUjian
    {
        return TokenUjian::create([
            'sesi_ruang_id' => $sesi->id,
            'kode' => $this->kodeUnik(),
            'aktif' => true,
            'dibuat_oleh' => $pembuat->id,
        ]);
    }

    /**
     * Token 6 karakter huruf kapital dan angka tanpa karakter ambigu (0, O, 1, I).
     */
    public function kodeUnik(): string
    {
        $karakter = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $kode = '';
            for ($i = 0; $i < 6; $i++) {
                $kode .= $karakter[random_int(0, strlen($karakter) - 1)];
            }
        } while (TokenUjian::where('kode', $kode)->where('aktif', true)->exists());

        return $kode;
    }
}
