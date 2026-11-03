<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'kehadiran.*.peserta_id' => 'required|distinct|exists:peserta_ujian,id',
            'kehadiran.*.hadir' => 'required|boolean',
        ]);

        $pesertaIds = collect($validated['kehadiran'])->pluck('peserta_id')->unique();
        $jumlahPesertaSesi = PesertaUjian::where('sesi_ruang_id', $sesiRuangId)
            ->whereIn('id', $pesertaIds)
            ->count();

        if ($jumlahPesertaSesi !== $pesertaIds->count()) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Daftar kehadiran memuat peserta dari sesi lain.']], 422);
        }

        DB::transaction(function () use ($validated, $sesiRuangId): void {
            foreach ($validated['kehadiran'] as $item) {
                PesertaUjian::where('id', $item['peserta_id'])
                    ->where('sesi_ruang_id', $sesiRuangId)
                    ->update(['hadir' => $item['hadir']]);
            }
        });

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
        $soalIds = $jadwal->soal_snapshot ?? $jadwal->paketSoal->items->pluck('bank_soal_id')->all();
        $soalCollection = BankSoal::with('opsiSoal')->whereIn('id', $soalIds)->get();
        if ($soalCollection->isEmpty()) {
            return response()->json(['error' => ['code' => 'EMPTY_EXAM', 'message' => 'Paket soal tidak memiliki butir soal.']], 422);
        }

        $now = Carbon::now();
        $waktuSelesai = $this->waktuSelesaiSesi($sesi, $jadwal, $now);
        if ($waktuSelesai->lessThanOrEqualTo($now)) {
            return response()->json(['error' => ['code' => 'TIME_UP', 'message' => 'Batas waktu sesi ujian telah lewat.']], 422);
        }

        $startedCount = 0;

        DB::transaction(function () use ($sesi, $soalCollection, $now, $waktuSelesai, &$startedCount) {
            $pesertaList = PesertaUjian::query()
                ->where('sesi_ruang_id', $sesi->id)
                ->where('status', 'menunggu')
                ->where('hadir', true)
                ->lockForUpdate()
                ->get();

            foreach ($pesertaList as $peserta) {
                $this->inisialisasiPengerjaanPeserta($peserta, $soalCollection, $now, $waktuSelesai);
                $startedCount++;
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

        $soalIds = $jadwal->soal_snapshot ?? $jadwal->paketSoal->items->pluck('bank_soal_id')->all();
        $soalCollection = BankSoal::with('opsiSoal')->whereIn('id', $soalIds)->get();
        if ($soalCollection->isEmpty()) {
            return response()->json(['error' => ['code' => 'EMPTY_EXAM', 'message' => 'Paket soal tidak memiliki butir soal.']], 422);
        }

        $now = Carbon::now();
        $waktuSelesai = $this->waktuSelesaiSesi($sesi, $jadwal, $now);
        if ($waktuSelesai->lessThanOrEqualTo($now)) {
            return response()->json(['error' => ['code' => 'TIME_UP', 'message' => 'Batas waktu sesi ujian telah lewat.']], 422);
        }

        DB::transaction(function () use ($pesertaId, $soalCollection, $now, $waktuSelesai): void {
            $peserta = PesertaUjian::query()->whereKey($pesertaId)->lockForUpdate()->firstOrFail();
            if ($peserta->status !== 'menunggu' || ! $peserta->hadir) {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Peserta harus diverifikasi hadir sebelum ujian dimulai.'],
                ], 422));
            }

            $this->inisialisasiPengerjaanPeserta($peserta, $soalCollection, $now, $waktuSelesai);
        });

        return response()->json([
            'message' => 'Ujian berhasil dimulai untuk peserta ini.',
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
                $mapping = [];
                if ($soal->tipe === 'menjodohkan') {
                    $kiri = $soal->opsiSoal->where('sisi', 'kiri');
                    $kanan = $soal->opsiSoal->where('sisi', 'kanan')->shuffle();
                    $kiriIds = $kiri->map(function ($opsi) use (&$mapping): string {
                        $id = (string) Str::uuid();
                        $mapping[$id] = ['id' => $opsi->id, 'answer' => (string) $opsi->urutan];

                        return $id;
                    })->values()->all();
                    $kananIds = $kanan->map(function ($opsi) use (&$mapping): string {
                        $id = (string) Str::uuid();
                        $mapping[$id] = ['id' => $opsi->id, 'answer' => chr(96 + (int) $opsi->urutan)];

                        return $id;
                    })->values()->all();
                    $urutanOpsiMap[$soal->id] = ['kiri' => $kiriIds, 'kanan' => $kananIds, 'options' => $mapping];
                } else {
                    $order = $soal->opsiSoal->shuffle()->map(function ($opsi) use (&$mapping, $soal): string {
                        $id = (string) Str::uuid();
                        $mapping[$id] = [
                            'id' => $opsi->id,
                            'answer' => $soal->tipe === 'benar_salah'
                                ? (string) $opsi->urutan
                                : chr(64 + (int) $opsi->urutan),
                        ];

                        return $id;
                    })->values()->all();
                    $urutanOpsiMap[$soal->id] = ['order' => $order, 'options' => $mapping];
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
        if (! in_array($role, ['operator', 'superadmin'], true)) {
            abort(response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Hanya Operator dan Superadmin yang berwenang memverifikasi kehadiran dan memulai ujian.',
                ],
            ], 403));
        }
    }

    private function waktuSelesaiSesi(SesiRuang $sesi, $jadwal, Carbon $waktuMulai): Carbon
    {
        $batasSesi = Carbon::parse(
            $jadwal->tanggal->format('Y-m-d').' '.$sesi->jam_akhir,
            'Asia/Jakarta'
        )->setTimezone($waktuMulai->timezone);

        return $waktuMulai->copy()->addMinutes($jadwal->durasi_menit)->min($batasSesi);
    }
}
