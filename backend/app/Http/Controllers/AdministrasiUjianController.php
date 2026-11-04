<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimpanBeritaAcaraRequest;
use App\Models\BeritaAcara;
use App\Models\LogAktivitas;
use App\Models\SesiRuang;
use App\Services\AuditLogService;
use App\Services\OtorisasiCbtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministrasiUjianController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly OtorisasiCbtService $otorisasi,
    ) {}

    public function beritaAcara(Request $request, int $sesiRuangId): JsonResponse
    {
        if (! $this->otorisasi->bolehAwasiSesi($request->user(), $sesiRuangId)) {
            return $this->error('FORBIDDEN', 'Anda tidak ditugaskan mengawasi sesi ini.', 403);
        }

        $beritaAcara = BeritaAcara::query()
            ->with(['pengawas:id,name', 'sesiRuang.ruang', 'sesiRuang.jadwalUjian'])
            ->where('sesi_ruang_id', $sesiRuangId)
            ->first();

        return response()->json(['data' => $beritaAcara]);
    }

    public function simpanBeritaAcara(SimpanBeritaAcaraRequest $request, int $sesiRuangId): JsonResponse
    {
        if (! $this->otorisasi->bolehAwasiSesi($request->user(), $sesiRuangId)) {
            return $this->error('FORBIDDEN', 'Anda tidak ditugaskan mengawasi sesi ini.', 403);
        }

        $sesi = SesiRuang::query()->withCount([
            'pesertaUjian as jumlah_hadir' => fn ($query) => $query->where('hadir', true),
            'pesertaUjian as jumlah_absen' => fn ($query) => $query->where('hadir', false),
        ])->findOrFail($sesiRuangId);

        $beritaAcara = DB::transaction(function () use ($request, $sesiRuangId, $sesi): BeritaAcara {
            $beritaAcara = BeritaAcara::query()->updateOrCreate(
                ['sesi_ruang_id' => $sesiRuangId],
                [
                    'pengawas_id' => $request->user()->id,
                    'isi' => $request->validated('isi'),
                    'jumlah_hadir' => $sesi->jumlah_hadir,
                    'jumlah_absen' => $sesi->jumlah_absen,
                ],
            );
            $this->auditLog->record(
                $request->user(),
                'berita_acara.disimpan',
                'sesi_ruang',
                $sesiRuangId,
                ['jumlah_hadir' => $sesi->jumlah_hadir, 'jumlah_absen' => $sesi->jumlah_absen],
                $request,
            );

            return $beritaAcara;
        });

        return response()->json(['data' => $beritaAcara], $beritaAcara->wasRecentlyCreated ? 201 : 200);
    }

    public function logAktivitas(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'aksi' => ['nullable', 'string', 'max:100'],
            'target_type' => ['nullable', 'string', 'max:100'],
            'mulai' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:mulai'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = LogAktivitas::query()
            ->with('aktor:id,name,username,role')
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['aksi']), fn ($query) => $query->where('aksi', $filters['aksi']))
            ->when(isset($filters['target_type']), fn ($query) => $query->where('target_type', $filters['target_type']))
            ->when(isset($filters['mulai']), fn ($query) => $query->where('created_at', '>=', $filters['mulai']))
            ->when(isset($filters['sampai']), fn ($query) => $query->where('created_at', '<=', $filters['sampai']))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json($rows);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
