<?php

namespace App\Console\Commands;

use App\Events\PesertaStatusChanged;
use App\Events\PesertaSubmitted;
use App\Events\WaktuHabis;
use App\Jobs\ProsesPenilaianPeserta;
use App\Models\PesertaUjian;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:submit-peserta-kedaluwarsa')]
#[Description('Submit peserta ujian yang waktu servernya telah habis.')]
class SubmitPesertaKedaluwarsa extends Command
{
    public function handle(AuditLogService $auditLog): int
    {
        $pesertaIds = PesertaUjian::query()
            ->whereIn('status', ['mengerjakan', 'terkunci'])
            ->whereNotNull('waktu_selesai')
            ->where('waktu_selesai', '<=', now())
            ->pluck('id');

        foreach ($pesertaIds as $pesertaId) {
            $sesiRuangId = DB::transaction(function () use ($pesertaId, $auditLog): ?int {
                $peserta = PesertaUjian::query()
                    ->whereKey($pesertaId)
                    ->lockForUpdate()
                    ->first();

                if (! $peserta || ! in_array($peserta->status, ['mengerjakan', 'terkunci'], true) || ! $peserta->waktu_selesai || $peserta->waktu_selesai->isFuture()) {
                    return null;
                }

                $peserta->update(['status' => 'selesai']);
                $auditLog->record(
                    null,
                    'ujian.dikirim_otomatis',
                    'peserta_ujian',
                    (int) $pesertaId,
                    ['alasan' => 'waktu_habis'],
                );

                return $peserta->sesi_ruang_id;
            });

            if ($sesiRuangId) {
                ProsesPenilaianPeserta::dispatch($pesertaId);
                event(new PesertaStatusChanged((int) $pesertaId, $sesiRuangId, 'selesai'));
                event(new PesertaSubmitted((int) $pesertaId, $sesiRuangId));
                event(new WaktuHabis((int) $pesertaId));
            }
        }

        return self::SUCCESS;
    }
}
