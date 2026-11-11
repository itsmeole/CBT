<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateLaporanPdf;
use App\Models\JadwalUjian;
use App\Models\LaporanPdf;
use App\Services\AuditLogService;
use App\Services\OtorisasiCbtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanPdfController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly OtorisasiCbtService $otorisasi,
    ) {}

    public function store(Request $request, string $jenis): JsonResponse
    {
        if (! in_array($jenis, ['kartu-peserta', 'daftar-hadir', 'daftar-hadir-semua', 'berita-acara', 'rekap-nilai'], true)) {
            return $this->error('NOT_FOUND', 'Jenis laporan PDF tidak ditemukan.', 404);
        }

        $validated = $request->validate([
            'jadwal_id' => [
                in_array($jenis, ['kartu-peserta', 'rekap-nilai', 'daftar-hadir-semua'], true) ? 'required' : 'prohibited',
                'integer',
                'exists:jadwal_ujian,id',
            ],
            'sesi_ruang_id' => [
                in_array($jenis, ['daftar-hadir', 'berita-acara'], true) ? 'required' : 'prohibited',
                'integer',
                'exists:sesi_ruang,id',
            ],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ]);
        $jadwalId = isset($validated['jadwal_id']) ? (int) $validated['jadwal_id'] : null;
        $sesiId = isset($validated['sesi_ruang_id']) ? (int) $validated['sesi_ruang_id'] : null;
        $kelasId = isset($validated['kelas_id']) ? (int) $validated['kelas_id'] : null;

        if ($jenis === 'kartu-peserta' && ! in_array($request->user()->role, ['operator', 'superadmin'], true)) {
            return $this->error('FORBIDDEN', 'Hanya Operator atau Superadmin yang dapat mencetak kartu peserta.', 403);
        }

        if ($jenis === 'daftar-hadir-semua' && ! in_array($request->user()->role, ['operator', 'superadmin'], true)) {
            return $this->error('FORBIDDEN', 'Hanya Operator atau Superadmin yang dapat mencetak daftar hadir semua ruang.', 403);
        }

        if ($jenis === 'rekap-nilai') {
            $jadwal = JadwalUjian::findOrFail($jadwalId);
            if (! in_array($request->user()->role, ['guru', 'operator', 'superadmin'], true)
                || ($request->user()->role === 'guru' && ! $this->otorisasi->bolehAksesMapelKelas($request->user(), $jadwal, $kelasId))) {
                return $this->error('FORBIDDEN', 'Anda tidak berwenang mencetak rekap jadwal ini.', 403);
            }
        }

        if (in_array($jenis, ['daftar-hadir', 'berita-acara'], true)
            && ! $this->otorisasi->bolehAwasiSesi($request->user(), $sesiId)) {
            return $this->error('FORBIDDEN', 'Anda tidak ditugaskan mengawasi sesi ini.', 403);
        }

        $laporan = DB::transaction(function () use ($request, $jenis, $jadwalId, $sesiId, $kelasId): LaporanPdf {
            $laporan = LaporanPdf::query()->create([
                'diminta_oleh' => $request->user()->id,
                'jenis' => $jenis,
                'jadwal_ujian_id' => $jadwalId,
                'sesi_ruang_id' => $sesiId,
                'kelas_id' => $kelasId,
                'status' => 'pending',
            ]);
            $this->auditLog->record(
                $request->user(),
                'laporan_pdf.diminta',
                'laporan_pdf',
                $laporan->id,
                ['jenis' => $jenis],
                $request,
            );

            return $laporan;
        });
        GenerateLaporanPdf::dispatch($laporan->id)->onQueue((string) config('cbt.queue_laporan', 'reports'));

        return response()->json(['data' => $laporan->fresh()], 202);
    }

    public function show(Request $request, int $laporanId): JsonResponse|StreamedResponse
    {
        $laporan = LaporanPdf::query()->whereKey($laporanId)
            ->where('diminta_oleh', $request->user()->id)
            ->firstOrFail();

        if ($laporan->status !== 'completed') {
            return response()->json(['data' => $laporan], $laporan->status === 'failed' ? 409 : 202);
        }

        return Storage::disk('local')->download($laporan->file_path, $laporan->jenis.'.pdf');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
