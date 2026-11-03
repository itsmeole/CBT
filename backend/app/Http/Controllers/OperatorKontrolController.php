<?php

namespace App\Http\Controllers;

use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatorKontrolController extends Controller
{
    /**
     * Ambil data sesi ruang beserta daftar peserta dan statusnya.
     */
    public function getPeserta(Request $request, int $sesiRuangId): JsonResponse
    {
        $this->ensureOperatorOrSuperadmin($request);

        $sesi = SesiRuang::with([
            'ruang',
            'jadwalUjian.mapel',
            'tokenUjian',
            'pesertaUjian.siswa.kelas',
            'pesertaUjian.siswa.user',
        ])->findOrFail($sesiRuangId);

        return response()->json(['data' => $sesi]);
    }

    /**
     * Verifikasi kehadiran peserta ujian (Hanya Operator & Superadmin).
     */
    public function setKehadiran(Request $request, int $sesiRuangId): JsonResponse
    {
        $this->ensureOperatorOrSuperadmin($request);

        $validated = $request->validate([
            'kehadiran' => 'required|array',
            'kehadiran.*.peserta_id' => 'required|exists:peserta_ujian,id',
            'kehadiran.*.hadir' => 'required|boolean',
        ]);

        foreach ($validated['kehadiran'] as $item) {
            PesertaUjian::where('id', $item['peserta_id'])
                ->where('sesi_ruang_id', $sesiRuangId)
                ->update(['hadir' => $item['hadir']]);
        }

        return response()->json(['message' => 'Status kehadiran peserta berhasil diperbarui.']);
    }

    /**
     * Mulai ujian untuk seluruh peserta terverifikasi di sesi ruang ini (Hanya Operator & Superadmin).
     */
    public function mulaiSesi(Request $request, int $sesiRuangId): JsonResponse
    {
        $this->ensureOperatorOrSuperadmin($request);

        $sesi = SesiRuang::with(['jadwalUjian.paketSoal.items.bankSoal.opsiSoal', 'pesertaUjian'])->findOrFail($sesiRuangId);
        $jadwal = $sesi->jadwalUjian;
        $durasi = $jadwal->durasi_menit;

        $soalCollection = $jadwal->paketSoal->items->pluck('bankSoal');
        if ($soalCollection->isEmpty()) {
            return response()->json(['error' => ['code' => 'EMPTY_EXAM', 'message' => 'Paket soal tidak memiliki butir soal.']], 422);
        }

        $now = Carbon::now();
        $waktuSelesai = $now->copy()->addMinutes($durasi);

        $startedCount = 0;

        DB::transaction(function () use ($sesi, $soalCollection, $now, $waktuSelesai, &$startedCount) {
            foreach ($sesi->pesertaUjian as $peserta) {
                // Hanya mulai untuk siswa yang sudah berstatus 'menunggu' (sudah join token & diverifikasi)
                if (in_array($peserta->status, ['menunggu', 'belum_login'])) {
                    $this->inisialisasiPengerjaanPeserta($peserta, $soalCollection, $now, $waktuSelesai);
                    $startedCount++;
                }
            }
        });

        return response()->json([
            'message' => "Ujian berhasil dimulai untuk {$startedCount} peserta.",
            'waktu_mulai' => $now->toIso8601String(),
            'waktu_selesai' => $waktuSelesai->toIso8601String(),
        ]);
    }

    /**
     * Mulai ujian perorangan (untuk siswa terlambat) (Hanya Operator & Superadmin).
     */
    public function mulaiPeserta(Request $request, int $pesertaId): JsonResponse
    {
        $this->ensureOperatorOrSuperadmin($request);

        $peserta = PesertaUjian::with(['sesiRuang.jadwalUjian.paketSoal.items.bankSoal.opsiSoal'])->findOrFail($pesertaId);
        $sesi = $peserta->sesiRuang;
        $jadwal = $sesi->jadwalUjian;

        $soalCollection = $jadwal->paketSoal->items->pluck('bankSoal');

        $now = Carbon::now();
        $waktuSelesai = $now->copy()->addMinutes($jadwal->durasi_menit);

        $this->inisialisasiPengerjaanPeserta($peserta, $soalCollection, $now, $waktuSelesai);

        return response()->json([
            'message' => "Ujian berhasil dimulai untuk peserta ini.",
            'data' => $peserta->fresh(),
        ]);
    }

    /**
     * Inisialisasi pengacakan soal & opsi deterministik per siswa.
     */
    private function inisialisasiPengerjaanPeserta(PesertaUjian $peserta, $soalCollection, Carbon $now, Carbon $waktuSelesai): void
    {
        // Acak urutan soal
        $shuffledSoal = $soalCollection->shuffle();
        $urutanSoalIds = $shuffledSoal->pluck('id')->values()->toArray();

        // Acak urutan opsi per soal
        $urutanOpsiMap = [];
        foreach ($shuffledSoal as $soal) {
            if ($soal->opsiSoal && $soal->opsiSoal->isNotEmpty()) {
                if ($soal->tipe === 'menjodohkan') {
                    // Simpan urutan kiri dan kanan terpisah
                    $kiriIds = $soal->opsiSoal->where('sisi', 'kiri')->pluck('id')->values()->toArray();
                    $kananShuffled = $soal->opsiSoal->where('sisi', 'kanan')->shuffle()->pluck('id')->values()->toArray();
                    $urutanOpsiMap[$soal->id] = ['kiri' => $kiriIds, 'kanan' => $kananShuffled];
                } else {
                    $urutanOpsiMap[$soal->id] = $soal->opsiSoal->shuffle()->pluck('id')->values()->toArray();
                }
            }
        }

        $peserta->update([
            'status' => 'mengerjakan',
            'hadir' => true,
            'waktu_mulai' => $now,
            'waktu_selesai' => $waktuSelesai,
            'urutan_soal' => $urutanSoalIds,
            'urutan_opsi' => $urutanOpsiMap,
        ]);
    }

    private function ensureOperatorOrSuperadmin(Request $request): void
    {
        $role = $request->user()->role;
        if (!in_array($role, ['operator', 'superadmin'], true)) {
            abort(response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Hanya Operator dan Superadmin yang berwenang memverifikasi kehadiran dan memulai ujian.',
                ]
            ], 403));
        }
    }
}
