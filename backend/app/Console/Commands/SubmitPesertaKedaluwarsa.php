<?php

namespace App\Console\Commands;

use App\Jobs\ProsesPenilaianPeserta;
use App\Models\PesertaUjian;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:submit-peserta-kedaluwarsa')]
#[Description('Submit peserta ujian yang waktu servernya telah habis.')]
class SubmitPesertaKedaluwarsa extends Command
{
    public function handle(): int
    {
        $pesertaIds = PesertaUjian::query()
            ->whereIn('status', ['mengerjakan', 'terkunci'])
            ->whereNotNull('waktu_selesai')
            ->where('waktu_selesai', '<=', now())
            ->pluck('id');

        foreach ($pesertaIds as $pesertaId) {
            $submitted = DB::transaction(function () use ($pesertaId): bool {
                $peserta = PesertaUjian::query()
                    ->whereKey($pesertaId)
                    ->lockForUpdate()
                    ->first();

                if (! $peserta || ! in_array($peserta->status, ['mengerjakan', 'terkunci'], true) || ! $peserta->waktu_selesai || $peserta->waktu_selesai->isFuture()) {
                    return false;
                }

                $peserta->update(['status' => 'selesai']);

                return true;
            });

            if ($submitted) {
                ProsesPenilaianPeserta::dispatch($pesertaId);
            }
        }

        return self::SUCCESS;
    }
}
