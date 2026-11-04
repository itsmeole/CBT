<?php

namespace App\Http\Controllers;

use App\Http\Requests\KoreksiEssayRequest;
use App\Models\BankSoal;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\PesertaUjian;
use App\Services\AuditLogService;
use App\Services\OtorisasiCbtService;
use App\Services\PenilaianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KoreksiEssayController extends Controller
{
    public function __construct(
        private readonly OtorisasiCbtService $otorisasi,
        private readonly PenilaianService $penilaian,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jadwal_id' => ['required', 'integer', 'exists:jadwal_ujian,id'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ]);
        $jadwal = JadwalUjian::findOrFail($validated['jadwal_id']);
        $kelasId = isset($validated['kelas_id']) ? (int) $validated['kelas_id'] : null;

        if (! $this->otorisasi->bolehAksesMapelKelas($request->user(), $jadwal, $kelasId)) {
            return $this->error('FORBIDDEN', 'Anda tidak mengampu mapel atau kelas pada jadwal ini.', 403);
        }

        $essayIds = $this->soalJadwal($jadwal)
            ->where('tipe', 'essay')
            ->pluck('id');
        if ($essayIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $sesiIds = $jadwal->sesiRuang()->pluck('id');
        $pesertaQuery = PesertaUjian::query()
            ->with(['siswa.user', 'siswa.kelas', 'jawaban' => fn ($query) => $query->whereIn('bank_soal_id', $essayIds)])
            ->whereIn('sesi_ruang_id', $sesiIds)
            ->where('status', 'selesai');

        if ($request->user()->role === 'guru') {
            $kelasDiampu = GuruMapel::query()
                ->where('guru_id', $request->user()->guru->id)
                ->where('mapel_id', $jadwal->mapel_id)
                ->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id)
                ->when($kelasId !== null, fn ($query) => $query->where('kelas_id', $kelasId))
                ->pluck('kelas_id');
            $pesertaQuery->whereHas('siswa', fn ($query) => $query->whereIn('kelas_id', $kelasDiampu));
        } elseif ($kelasId !== null) {
            $pesertaQuery->whereHas('siswa', fn ($query) => $query->where('kelas_id', $kelasId));
        }

        $soalMap = $this->soalJadwal($jadwal)->where('tipe', 'essay')->get()->keyBy('id');
        $antrian = [];
        foreach ($pesertaQuery->orderBy('id')->get() as $peserta) {
            $jawabanMap = $peserta->jawaban->keyBy('bank_soal_id');
            foreach ($soalMap as $soalId => $soal) {
                $jawaban = $jawabanMap->get($soalId);
                if ($jawaban && $jawaban->dikoreksi_oleh !== null) {
                    continue;
                }

                $antrian[] = [
                    'jawaban_id' => $jawaban?->id,
                    'peserta_id' => $peserta->id,
                    'soal_id' => $soal->id,
                    'nama_siswa' => $peserta->siswa?->user?->name,
                    'nis' => $peserta->siswa?->nis,
                    'kelas' => $peserta->siswa?->kelas?->nama,
                    'konten_soal' => $soal->konten,
                    'kunci' => $soal->kunci,
                    'rubrik' => $soal->rubrik,
                    'jawaban' => $jawaban?->jawaban,
                    'skor' => $jawaban?->skor ?? '0.00',
                    'catatan' => $jawaban?->catatan_koreksi,
                    'skor_maksimal' => $jadwal->bobot_snapshot['essay'] ?? '1.00',
                ];
            }
        }

        return response()->json(['data' => $antrian]);
    }

    public function update(KoreksiEssayRequest $request, int $jawabanId): JsonResponse
    {
        $jawaban = JawabanSiswa::with(['pesertaUjian.siswa', 'pesertaUjian.sesiRuang.jadwalUjian', 'bankSoal'])->findOrFail($jawabanId);
        $peserta = $jawaban->pesertaUjian;
        $jadwal = $peserta->sesiRuang->jadwalUjian;
        $soal = $jawaban->bankSoal;

        if ($peserta->status !== 'selesai' || $soal->tipe !== 'essay' || ! $this->soalJadwal($jadwal)->whereKey($soal->id)->exists()) {
            return $this->error('INVALID_STATE', 'Jawaban ini bukan essay dari ujian yang sudah selesai.', 422);
        }

        if (! $this->otorisasi->bolehAksesMapelKelas($request->user(), $jadwal, $peserta->siswa->kelas_id)) {
            return $this->error('FORBIDDEN', 'Anda tidak mengampu mapel atau kelas jawaban ini.', 403);
        }

        $skor = $request->validated('skor');
        $maksimal = $jadwal->bobot_snapshot['essay'] ?? '1.00';
        if ($this->penilaian->nilaiKeSatuanDesimal($skor) > $this->penilaian->nilaiKeSatuanDesimal($maksimal)) {
            return $this->error('VALIDATION_ERROR', 'Skor essay tidak boleh melebihi bobot maksimal soal.', 422);
        }

        $nilai = DB::transaction(function () use ($jawabanId, $request, $skor): mixed {
            $jawaban = JawabanSiswa::query()->whereKey($jawabanId)->lockForUpdate()->firstOrFail();
            if ($jawaban->dikoreksi_oleh !== null) {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Jawaban essay ini sudah dikoreksi.'],
                ], 422));
            }

            $peserta = PesertaUjian::query()
                ->whereKey($jawaban->peserta_ujian_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($peserta->status !== 'selesai') {
                abort(response()->json([
                    'error' => ['code' => 'INVALID_STATE', 'message' => 'Ujian belum selesai.'],
                ], 422));
            }

            $jawaban->update([
                'skor' => $skor,
                'dikoreksi_oleh' => $request->user()->id,
                'catatan_koreksi' => $request->validated('catatan'),
            ]);

            $peserta->load('sesiRuang.jadwalUjian');

            $nilai = $this->penilaian->nilaiPeserta($peserta);
            $this->auditLog->record(
                $request->user(),
                'essay.dikoreksi',
                'jawaban_siswa',
                $jawabanId,
                ['skor' => $skor, 'nilai_status' => $nilai->status],
                $request,
            );

            return $nilai;
        });

        return response()->json([
            'message' => 'Skor essay berhasil disimpan.',
            'data' => [
                'jawaban_id' => $jawabanId,
                'skor' => $skor,
                'nilai_status' => $nilai->status,
            ],
        ]);
    }

    private function soalJadwal(JadwalUjian $jadwal)
    {
        $soalIds = $jadwal->soal_snapshot
            ?? $jadwal->paketSoal->items()->pluck('bank_soal_id')->all();

        return BankSoal::query()->whereIn('id', $soalIds);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
