<?php

namespace App\Jobs;

use App\Models\PesertaUjian;
use App\Services\PenilaianService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProsesPenilaianPeserta implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $pesertaId) {}

    public function handle(PenilaianService $penilaianService): void
    {
        $peserta = PesertaUjian::find($this->pesertaId);

        if ($peserta && $peserta->status === 'selesai') {
            $penilaianService->nilaiPeserta($peserta);
        }
    }
}
