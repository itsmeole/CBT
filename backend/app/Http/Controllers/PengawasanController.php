<?php

namespace App\Http\Controllers;

use App\Events\ForceLogout;
use App\Events\LockThresholdReached;
use App\Events\PesertaLocked;
use App\Events\PesertaStatusChanged;
use App\Events\PesertaUnlocked;
use App\Events\SesiUnlocked;
use App\Http\Requests\BukaKunciPesertaRequest;
use App\Http\Requests\KunciUjianRequest;
use App\Http\Requests\UpdateAmbangKunciRequest;
use App\Models\LockEvent;
use App\Models\Pengaturan;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OtorisasiCbtService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PengawasanController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly OtorisasiCbtService $otorisasi,
    ) {}

    public function lock(KunciUjianRequest $request): JsonResponse
    {
        $siswaId = $request->user()->siswa?->id;
        $peserta = $siswaId
            ? PesertaUjian::query()
                ->where('siswa_id', $siswaId)
                ->whereIn('status', ['mengerjakan', 'terkunci'])
                ->latest('id')
                ->first()
            : null;

        if (! $peserta) {
            return $this->error('INVALID_STATE', 'Tidak ada ujian aktif yang dapat dikunci.', 422);
        }

        $hasil = DB::transaction(function () use ($peserta, $request): array {
            $terkunci = PesertaUjian::query()->whereKey($peserta->id)->lockForUpdate()->firstOrFail();
            if ($terkunci->status === 'terkunci') {
                return ['baru' => false, 'peserta' => $terkunci];
            }

            if ($terkunci->status !== 'mengerjakan') {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Ujian tidak sedang berlangsung.'],
                ], 422));
            }

            $waktuKunci = Carbon::now();
            $jumlahKunci = $terkunci->jumlah_kunci + 1;
            $terkunci->update(['status' => 'terkunci', 'jumlah_kunci' => $jumlahKunci]);
            LockEvent::create([
                'peserta_ujian_id' => $terkunci->id,
                'pemicu' => $request->validated('pemicu'),
                'waktu_kunci' => $waktuKunci,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            ]);
            $this->auditLog->record(
                $request->user(),
                'ujian.lock',
                'peserta_ujian',
                $terkunci->id,
                ['pemicu' => $request->validated('pemicu'), 'jumlah_kunci' => $jumlahKunci],
                $request,
            );

            return ['baru' => true, 'peserta' => $terkunci->fresh()];
        });

        if ($hasil['baru']) {
            $terkunci = $hasil['peserta'];
            event(new PesertaLocked($terkunci->id, $terkunci->sesi_ruang_id, $terkunci->jumlah_kunci));
            event(new PesertaStatusChanged($terkunci->id, $terkunci->sesi_ruang_id, 'terkunci'));

            $ambang = (int) (Pengaturan::query()->where('key', 'ambang_kunci')->value('value') ?? 3);
            if ($terkunci->jumlah_kunci === $ambang) {
                event(new LockThresholdReached($terkunci->id, $terkunci->sesi_ruang_id, $terkunci->jumlah_kunci));
            }
        }

        return response()->json([
            'data' => [
                'status' => $hasil['peserta']->status,
                'jumlah_kunci' => $hasil['peserta']->jumlah_kunci,
                'waktu_selesai' => $hasil['peserta']->waktu_selesai?->toIso8601String(),
            ],
        ]);
    }

    public function unlock(BukaKunciPesertaRequest $request, int $pesertaId): JsonResponse
    {
        $peserta = PesertaUjian::findOrFail($pesertaId);
        if (! $this->otorisasi->bolehAwasiSesi($request->user(), $peserta->sesi_ruang_id)) {
            return $this->error('FORBIDDEN', 'Anda tidak ditugaskan mengawasi sesi ini.', 403);
        }

        $hasil = DB::transaction(function () use ($pesertaId, $request): array {
            $peserta = PesertaUjian::query()->whereKey($pesertaId)->lockForUpdate()->firstOrFail();
            if ($peserta->status !== 'terkunci') {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Peserta tidak sedang terkunci.'],
                ], 422));
            }

            $lockEvent = LockEvent::query()
                ->where('peserta_ujian_id', $peserta->id)
                ->whereNull('waktu_buka')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $lockEvent) {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Riwayat penguncian aktif tidak ditemukan.'],
                ], 422));
            }

            $lockEvent->update([
                'waktu_buka' => Carbon::now(),
                'dibuka_oleh' => $request->user()->id,
                'alasan' => $request->validated('alasan'),
            ]);
            $peserta->update(['status' => 'mengerjakan']);
            $this->auditLog->record(
                $request->user(),
                'ujian.unlock',
                'peserta_ujian',
                $peserta->id,
                ['alasan' => $request->validated('alasan')],
                $request,
            );

            return ['peserta' => $peserta->fresh(), 'waktu_selesai' => $peserta->waktu_selesai];
        });

        event(new PesertaUnlocked($peserta->id, $peserta->sesi_ruang_id));
        event(new SesiUnlocked($peserta->id));
        event(new PesertaStatusChanged($peserta->id, $peserta->sesi_ruang_id, 'mengerjakan'));

        return response()->json([
            'message' => 'Peserta berhasil dibuka kuncinya.',
            'data' => [
                'peserta_id' => $hasil['peserta']->id,
                'status' => $hasil['peserta']->status,
                'waktu_selesai' => $hasil['waktu_selesai']?->toIso8601String(),
            ],
        ]);
    }

    public function monitoring(Request $request, int $sesiRuangId): JsonResponse
    {
        if (! $this->otorisasi->bolehAwasiSesi($request->user(), $sesiRuangId)) {
            return $this->error('FORBIDDEN', 'Anda tidak ditugaskan mengawasi sesi ini.', 403);
        }

        $sesi = SesiRuang::with(['jadwalUjian.mapel', 'ruang'])->findOrFail($sesiRuangId);
        $peserta = PesertaUjian::query()
            ->with(['siswa.user', 'siswa.kelas', 'jawaban'])
            ->where('sesi_ruang_id', $sesiRuangId)
            ->orderBy('id')
            ->get()
            ->map(function (PesertaUjian $item): array {
                $terjawab = $item->jawaban->filter(function ($jawaban): bool {
                    $value = $jawaban->jawaban;

                    return $value !== null && $value !== '' && $value !== [];
                })->count();

                return [
                    'id' => $item->id,
                    'nama' => $item->siswa?->user?->name,
                    'nis' => $item->siswa?->nis,
                    'kelas' => $item->siswa?->kelas?->nama,
                    'status' => $item->status,
                    'terjawab' => $terjawab,
                    'jumlah_kunci' => $item->jumlah_kunci,
                    'sisa_detik' => $item->waktu_selesai
                        ? max(0, (int) Carbon::now()->diffInSeconds($item->waktu_selesai, false))
                        : 0,
                ];
            });

        return response()->json([
            'data' => [
                'sesi' => [
                    'id' => $sesi->id,
                    'jadwal' => $sesi->jadwalUjian?->nama,
                    'mapel' => $sesi->jadwalUjian?->mapel?->nama,
                    'ruang' => $sesi->ruang?->nama,
                ],
                'peserta' => $peserta,
            ],
        ]);
    }

    public function resetLogin(Request $request, int $userId): JsonResponse
    {
        $user = User::with('siswa')->findOrFail($userId);
        if (! $user->siswa || ! $this->otorisasi->bolehResetSiswa($request->user(), $user)) {
            return $this->error('FORBIDDEN', 'Anda tidak berwenang mereset login siswa ini.', 403);
        }

        $pesertaIds = DB::transaction(function () use ($user, $request): array {
            $pesertaList = PesertaUjian::query()
                ->where('siswa_id', $user->siswa->id)
                ->whereIn('status', ['menunggu', 'mengerjakan', 'terkunci'])
                ->get();
            $pesertaIds = $pesertaList->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($pesertaList as $p) {
                if ($p->status === 'terkunci') {
                    $p->update(['status' => 'mengerjakan']);
                    LockEvent::query()
                        ->where('peserta_ujian_id', $p->id)
                        ->whereNull('waktu_buka')
                        ->update([
                            'waktu_buka' => Carbon::now(),
                            'dibuka_oleh' => $request->user()->id,
                            'alasan' => 'Reset login sesi oleh pengawas/guru',
                        ]);
                }
            }

            $user->tokens()->delete();
            $user->deviceSessions()->where('aktif', true)->update(['aktif' => false]);
            $this->auditLog->record(
                $request->user(),
                'auth.reset_login',
                'user',
                $user->id,
                ['peserta_ids' => $pesertaIds],
                $request,
            );

            return $pesertaIds;
        });
        foreach ($pesertaIds as $pesertaId) {
            event(new ForceLogout($pesertaId));
        }

        return response()->json(['message' => 'Login siswa berhasil direset.']);
    }

    public function showAmbangKunci(): JsonResponse
    {
        $ambang = (int) (Pengaturan::query()->where('key', 'ambang_kunci')->value('value') ?? 3);

        return response()->json(['data' => ['ambang_kunci' => $ambang]]);
    }

    public function updateAmbangKunci(UpdateAmbangKunciRequest $request): JsonResponse
    {
        $ambang = $request->validated('ambang_kunci');
        DB::transaction(function () use ($request, $ambang): void {
            Pengaturan::query()->updateOrCreate(['key' => 'ambang_kunci'], ['value' => (string) $ambang]);
            $this->auditLog->record(
                $request->user(),
                'lock_threshold.updated',
                'pengaturan',
                null,
                ['ambang_kunci' => $ambang],
                $request,
            );
        });

        return response()->json(['data' => ['ambang_kunci' => $ambang]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
