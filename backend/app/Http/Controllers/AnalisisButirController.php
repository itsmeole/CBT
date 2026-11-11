<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\JadwalUjian;
use App\Models\PesertaUjian;
use App\Services\OtorisasiCbtService;
use App\Services\PenilaianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalisisButirController extends Controller
{
    public function __construct(
        private readonly OtorisasiCbtService $otorisasi,
        private readonly PenilaianService $penilaian,
    ) {}

    public function show(Request $request, int $jadwalId): JsonResponse
    {
        $jadwal = JadwalUjian::query()->findOrFail($jadwalId);
        $kelasId = $request->validate([
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ])['kelas_id'] ?? null;

        if (! $this->otorisasi->bolehAksesMapelKelas($request->user(), $jadwal, $kelasId)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berwenang melihat analisis jadwal ini.'],
            ], 403);
        }

        $soalIds = $jadwal->soal_snapshot
            ?? $jadwal->paketSoal->items()->orderBy('urutan')->pluck('bank_soal_id')->all();
        $soalList = BankSoal::query()->whereIn('id', $soalIds)->get()->keyBy('id');
        $maxScores = array_map('floatval', app(PenilaianService::class)->bobotPerSoal($jadwal, $soalList));
        $pesertaQuery = PesertaUjian::query()
            ->with([
                'siswa.kelas',
                'nilai',
                'jawaban' => fn ($query) => $query->whereIn('bank_soal_id', $soalList->keys()),
            ])
            ->whereIn('sesi_ruang_id', $jadwal->sesiRuang()->select('id'))
            ->where('status', 'selesai')
            ->whereHas('nilai', fn ($query) => $query->where('status', 'final'));

        if ($request->user()->role === 'guru') {
            $kelasDiampu = $request->user()->guru->guruMapel()
                ->where('mapel_id', $jadwal->mapel_id)
                ->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id)
                ->when($kelasId !== null, fn ($query) => $query->where('kelas_id', $kelasId))
                ->pluck('kelas_id');
            $pesertaQuery->whereHas('siswa', fn ($query) => $query->whereIn('kelas_id', $kelasDiampu));
        } elseif ($kelasId !== null) {
            $pesertaQuery->whereHas('siswa', fn ($query) => $query->where('kelas_id', $kelasId));
        }

        $peserta = $pesertaQuery->get();
        $peringkat = $peserta->sortBy(fn (PesertaUjian $item): float => (float) $item->nilai->total)
            ->values();
        $jumlahKelompok = $peringkat->count() >= 3
            ? max(1, (int) floor($peringkat->count() * 0.27))
            : 0;
        $bawah = $jumlahKelompok > 0 ? $peringkat->take($jumlahKelompok)->pluck('id')->all() : [];
        $atas = $jumlahKelompok > 0 ? $peringkat->reverse()->take($jumlahKelompok)->pluck('id')->all() : [];

        $hasil = [];
        foreach ($soalIds as $urutan => $soalId) {
            $soal = $soalList->get($soalId);
            if (! $soal) {
                continue;
            }

            $skorNormalized = [];
            foreach ($peserta as $item) {
                $jawaban = $item->jawaban->firstWhere('bank_soal_id', $soal->id);
                $skor = $soal->tipe === 'essay'
                    ? (float) ($jawaban?->skor ?? 0)
                    : (float) $this->penilaian->hitungSkorSoal(
                        $soal->tipe,
                        $jawaban?->jawaban,
                        $soal->kunci,
                        (string) $maxScores[$soal->id],
                    );
                $skorNormalized[$item->id] = $maxScores[$soal->id] > 0
                    ? min(1, max(0, $skor / $maxScores[$soal->id]))
                    : 0;
            }

            $p = count($skorNormalized) > 0
                ? array_sum($skorNormalized) / count($skorNormalized)
                : null;
            $discrimination = $jumlahKelompok > 0
                ? $this->averageFor($skorNormalized, $atas) - $this->averageFor($skorNormalized, $bawah)
                : null;

            $hasil[] = [
                'nomor' => $urutan + 1,
                'soal_id' => (int) $soal->id,
                'tipe' => $soal->tipe,
                'skor_maksimal' => number_format($maxScores[$soal->id], 2, '.', ''),
                'jumlah_peserta_final' => count($skorNormalized),
                'indeks_kesukaran' => $p === null ? null : round($p, 4),
                'daya_pembeda' => $discrimination === null ? null : round($discrimination, 4),
            ];
        }

        return response()->json([
            'data' => [
                'jadwal_id' => $jadwal->id,
                'jumlah_peserta_final' => $peserta->count(),
                'metode' => [
                    'indeks_kesukaran' => 'rata-rata skor butir dibagi skor maksimal, rentang 0 sampai 1',
                    'daya_pembeda' => 'selisih rata-rata proporsi skor kelompok atas dan bawah (masing-masing 27%); null bila peserta final kurang dari 3',
                ],
                'butir' => $hasil,
            ],
        ]);
    }

    /**
     * @param  array<int, float>  $scores
     * @param  array<int>  $participantIds
     */
    private function averageFor(array $scores, array $participantIds): float
    {
        if ($participantIds === []) {
            return 0;
        }

        return array_sum(array_map(
            fn (int $id): float => $scores[$id] ?? 0,
            $participantIds,
        )) / count($participantIds);
    }
}
