<?php

use App\Models\PesertaUjian;
use App\Models\User;
use App\Services\OtorisasiCbtService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('sesi.{sesiRuangId}', function (User $user, int $sesiRuangId): bool {
    return app(OtorisasiCbtService::class)->bolehAwasiSesi($user, $sesiRuangId);
});

Broadcast::channel('peserta.{pesertaId}', function (User $user, int $pesertaId): bool {
    return PesertaUjian::query()
        ->whereKey($pesertaId)
        ->whereHas('siswa', fn ($query) => $query->where('user_id', $user->id))
        ->exists();
});
