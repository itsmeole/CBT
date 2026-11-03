<?php

namespace App\Http\Controllers;

use App\Http\Requests\AutosaveJawabanRequest;
use App\Jobs\ProsesPenilaianPeserta;
use App\Models\BankSoal;
use App\Models\JawabanSiswa;
use App\Models\PesertaUjian;
use App\Models\TokenUjian;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UjianController extends Controller
{
    /**
     * Siswa memasukkan token untuk bergabung ke sesi ujian (FR-BE-60).
     * Status beralih ke 'menunggu' (menunggu Operator menekan Mulai).
     */
    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:6',
        ]);

        $tokenInput = strtoupper(trim($validated['token']));
        $token = TokenUjian::with('sesiRuang.jadwalUjian')->where('kode', $tokenInput)->where('aktif', true)->first();

        if (! $token) {
            return response()->json(['error' => ['code' => 'TOKEN_INVALID', 'message' => 'Token ujian tidak valid atau sudah kadaluarsa.']], 422);
        }

        $sesi = $token->sesiRuang;
        $siswa = $request->user()->siswa;

        if (! $siswa) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya akun siswa yang dapat mengikuti ujian.']], 403);
        }

        $jadwal = $sesi->jadwalUjian;
        if ($jadwal->tanggal->format('Y-m-d') !== Carbon::now('Asia/Jakarta')->format('Y-m-d')) {
            return response()->json(['error' => ['code' => 'TOKEN_INVALID', 'message' => 'Jadwal ujian tidak berlaku hari ini.']], 422);
        }

        $batasSesi = Carbon::parse($jadwal->tanggal->format('Y-m-d').' '.$sesi->jam_akhir, 'Asia/Jakarta');
        if (Carbon::now()->greaterThanOrEqualTo($batasSesi)) {
            return response()->json(['error' => ['code' => 'TOKEN_INVALID', 'message' => 'Sesi ujian sudah ditutup.']], 422);
        }

        $peserta = PesertaUjian::where('siswa_id', $siswa->id)
            ->where('sesi_ruang_id', $sesi->id)
            ->first();

        if (! $peserta) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak terdaftar sebagai peserta pada sesi ini.']], 403);
        }

        $pesertaAktifLain = PesertaUjian::where('siswa_id', $siswa->id)
            ->where('id', '!=', $peserta->id)
            ->whereIn('status', ['menunggu', 'mengerjakan', 'terkunci'])
            ->exists();
        if ($pesertaAktifLain) {
            return response()->json(['error' => ['code' => 'ACCOUNT_IN_USE', 'message' => 'Akun sedang mengikuti ujian lain. Selesaikan ujian aktif terlebih dahulu.']], 409);
        }

        if ($peserta->status === 'selesai') {
            return response()->json(['error' => ['code' => 'ALREADY_SUBMITTED', 'message' => 'Anda sudah menyelesaikan ujian ini.']], 422);
        }

        if ($peserta->status === 'belum_login') {
            $peserta->update(['status' => 'menunggu']);
        }

        return response()->json([
            'message' => 'Berhasil bergabung. Menunggu operator memulai ujian.',
            'data' => [
                'peserta_id' => $peserta->id,
                'status' => $peserta->status,
                'nama_ujian' => $sesi->jadwalUjian->nama,
                'durasi_menit' => $sesi->jadwalUjian->durasi_menit,
                'ruang' => $sesi->ruang->nama,
                'sesi_ke' => $sesi->sesi_ke,
            ],
        ]);
    }

    /**
     * Mengambil status peserta saat ini (recovery reload / koneksi putus).
     */
    public function state(Request $request): JsonResponse
    {
        $siswa = $request->user()->siswa;
        if (! $siswa) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Bukan akun siswa.']], 403);
        }

        $peserta = PesertaUjian::with(['sesiRuang.jadwalUjian.mapel', 'sesiRuang.ruang'])
            ->where('siswa_id', $siswa->id)
            ->whereIn('status', ['menunggu', 'mengerjakan', 'terkunci'])
            ->latest('id')
            ->first();

        if (! $peserta) {
            // Cek apakah ada yang baru saja selesai
            $selesai = PesertaUjian::where('siswa_id', $siswa->id)->where('status', 'selesai')->latest('id')->first();
            if ($selesai) {
                return response()->json(['data' => ['status' => 'selesai']]);
            }

            return response()->json(['data' => ['status' => 'belum_login']]);
        }

        // Auto-submit jika waktu habis
        if ($peserta->status === 'mengerjakan' || $peserta->status === 'terkunci') {
            if ($peserta->waktu_selesai && Carbon::now()->greaterThanOrEqualTo($peserta->waktu_selesai)) {
                $this->prosesSubmit($peserta);

                return response()->json(['data' => ['status' => 'selesai']]);
            }
        }

        $sisaDetik = 0;
        if ($peserta->waktu_selesai) {
            $sisaDetik = max(0, Carbon::now()->diffInSeconds($peserta->waktu_selesai, false));
        }

        $jawabanMap = [];
        $jawabanTersimpan = $peserta->jawaban()->get()->keyBy('bank_soal_id');
        $soalJawaban = BankSoal::whereIn('id', $jawabanTersimpan->keys())->pluck('tipe', 'id');
        foreach ($jawabanTersimpan as $soalId => $record) {
            $jawabanMap[$soalId] = $this->jawabanUntukSiswa(
                $peserta,
                (int) $soalId,
                $soalJawaban->get($soalId),
                $record->jawaban
            );
        }

        return response()->json([
            'data' => [
                'peserta_id' => $peserta->id,
                'status' => $peserta->status,
                'mapel' => $peserta->sesiRuang->jadwalUjian->mapel->nama,
                'nama_ujian' => $peserta->sesiRuang->jadwalUjian->nama,
                'waktu_mulai' => $peserta->waktu_mulai?->toIso8601String(),
                'waktu_selesai' => $peserta->waktu_selesai?->toIso8601String(),
                'waktu_selesai_ms' => $peserta->waktu_selesai ? $peserta->waktu_selesai->timestamp * 1000 : null,
                'sisa_detik' => $sisaDetik,
                'jumlah_soal' => is_array($peserta->urutan_soal) ? count($peserta->urutan_soal) : 0,
                'jawaban_tersimpan' => $jawabanMap,
            ],
        ]);
    }

    /**
     * Mengambil daftar butir soal untuk siswa saat status 'mengerjakan'.
     * PENTING: KUNCI JAWABAN, SKOR, BOBOT, DAN RUBRIK DIHAPUS TOTAL (FR-BE-63)!
     */
    public function getSoal(Request $request): JsonResponse
    {
        $siswa = $request->user()->siswa;
        $peserta = PesertaUjian::where('siswa_id', $siswa?->id)
            ->where('status', 'mengerjakan')
            ->latest('id')
            ->first();

        if (! $peserta) {
            return response()->json(['error' => ['code' => 'NOT_STARTED', 'message' => 'Ujian belum dimulai atau Anda sedang tidak dalam status mengerjakan.']], 403);
        }

        // Cek batas waktu
        if ($peserta->waktu_selesai && Carbon::now()->greaterThanOrEqualTo($peserta->waktu_selesai)) {
            $this->prosesSubmit($peserta);

            return response()->json(['error' => ['code' => 'TIME_UP', 'message' => 'Waktu ujian telah habis.']], 423);
        }

        $urutanSoalIds = $peserta->urutan_soal ?? [];
        $urutanOpsiMap = $peserta->urutan_opsi ?? [];

        $soalModels = BankSoal::with('opsiSoal')->whereIn('id', $urutanSoalIds)->get()->keyBy('id');

        $result = [];
        $nomor = 1;

        foreach ($urutanSoalIds as $soalId) {
            $soal = $soalModels->get($soalId);
            if (! $soal) {
                continue;
            }

            $opsiFormatted = [];
            $opsiOrderedIds = $urutanOpsiMap[$soalId] ?? [];
            $opsiMap = $opsiOrderedIds['options'] ?? [];

            if ($soal->tipe === 'menjodohkan' && is_array($opsiOrderedIds)) {
                $kiriIds = $opsiOrderedIds['kiri'] ?? [];
                $kananIds = $opsiOrderedIds['kanan'] ?? [];

                $opsiKeyed = $soal->opsiSoal->keyBy('id');

                $opsiFormatted = [
                    'kiri' => $this->formatOpsiOpaque($kiriIds, $opsiMap, $opsiKeyed),
                    'kanan' => $this->formatOpsiOpaque($kananIds, $opsiMap, $opsiKeyed),
                ];
            } else {
                $opsiKeyed = $soal->opsiSoal->keyBy('id');
                if (isset($opsiOrderedIds['order'], $opsiOrderedIds['options'])) {
                    $opsiFormatted = $this->formatOpsiOpaque($opsiOrderedIds['order'], $opsiMap, $opsiKeyed);
                } elseif (is_array($opsiOrderedIds) && ! empty($opsiOrderedIds)) {
                    foreach ($opsiOrderedIds as $opId) {
                        $op = $opsiKeyed->get($opId);
                        if ($op) {
                            $opsiFormatted[] = ['id' => $op->id, 'konten' => $op->konten];
                        }
                    }
                } else {
                    foreach ($soal->opsiSoal as $op) {
                        $opsiFormatted[] = ['id' => $op->id, 'konten' => $op->konten];
                    }
                }
            }

            // STRIP TOTAL kunci, rubrik, skor
            $result[] = [
                'nomor' => $nomor++,
                'soal_id' => $soal->id,
                'tipe' => $soal->tipe,
                'konten' => $soal->konten,
                'opsi' => $opsiFormatted,
            ];
        }

        return response()->json(['data' => $result]);
    }

    private function formatOpsiOpaque(array $urut, array $mapping, $opsiKeyed): array
    {
        $formatted = [];
        foreach ($urut as $opaqueId) {
            $mappingEntry = $mapping[$opaqueId] ?? null;
            $optionId = $mappingEntry['id'] ?? $opaqueId;
            $opsi = $opsiKeyed->get($optionId);
            if ($opsi) {
                $formatted[] = ['id' => $mappingEntry ? $opaqueId : $opsi->id, 'konten' => $opsi->konten];
            }
        }

        return $formatted;
    }

    /**
     * Autosave jawaban delta per nomor / batch dengan nomor sequence client_seq (FR-BE-64).
     */
    public function autosave(AutosaveJawabanRequest $request): JsonResponse
    {
        $siswa = $request->user()->siswa;
        $peserta = PesertaUjian::where('siswa_id', $siswa?->id)->latest('id')->first();

        if (! $peserta) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Peserta tidak ditemukan.']], 404);
        }

        $validated = $request->validated();
        $jadwal = $peserta->sesiRuang->jadwalUjian;
        $allowedSoalIds = $jadwal->soal_snapshot
            ?? $jadwal->paketSoal->items()->pluck('bank_soal_id')->all();
        $submittedSoalIds = collect($validated['items'])->pluck('soal_id')->map(fn ($id) => (int) $id);

        if ($submittedSoalIds->diff(array_map('intval', $allowedSoalIds))->isNotEmpty()) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Jawaban memuat soal yang tidak termasuk dalam ujian Anda.']], 422);
        }

        $soalMap = BankSoal::whereIn('id', $submittedSoalIds)->get()->keyBy('id');
        $timeUp = false;
        DB::transaction(function () use ($peserta, $validated, $soalMap, &$timeUp) {
            $peserta = PesertaUjian::query()->whereKey($peserta->id)->lockForUpdate()->firstOrFail();

            if ($peserta->status === 'terkunci') {
                abort(response()->json(['error' => ['code' => 'SESSION_LOCKED', 'message' => 'Ujian terkunci, hubungi pengawas.']], 423));
            }

            if ($peserta->status === 'selesai') {
                abort(response()->json(['error' => ['code' => 'ALREADY_SUBMITTED', 'message' => 'Ujian sudah diselesaikan.']], 423));
            }

            if ($peserta->status !== 'mengerjakan') {
                abort(response()->json(['error' => ['code' => 'NOT_STARTED', 'message' => 'Ujian belum dimulai.']], 403));
            }

            if ($peserta->waktu_selesai && Carbon::now()->greaterThanOrEqualTo($peserta->waktu_selesai)) {
                $peserta->update(['status' => 'selesai']);
                $timeUp = true;

                return;
            }

            foreach ($validated['items'] as $item) {
                $clientSeq = (int) $item['client_seq'];

                if ($clientSeq < $peserta->last_client_seq) {
                    continue;
                }

                // Abaikan jika client_seq lebih kecil dari yang sudah tersimpan (data basi)
                $existing = JawabanSiswa::where('peserta_ujian_id', $peserta->id)
                    ->where('bank_soal_id', $item['soal_id'])
                    ->first();

                if ($existing && $existing->client_seq >= $clientSeq) {
                    continue;
                }

                JawabanSiswa::updateOrCreate(
                    ['peserta_ujian_id' => $peserta->id, 'bank_soal_id' => $item['soal_id']],
                    [
                        'jawaban' => $this->normalisasiJawaban(
                            $peserta,
                            (int) $item['soal_id'],
                            $soalMap->get($item['soal_id'])?->tipe,
                            $item['jawaban']
                        ),
                        'client_seq' => $clientSeq,
                    ]
                );

                $peserta->last_client_seq = max($peserta->last_client_seq, $clientSeq);
            }
            $peserta->save();
        });

        if ($timeUp) {
            ProsesPenilaianPeserta::dispatch($peserta->id);

            return response()->json(['error' => ['code' => 'TIME_UP', 'message' => 'Waktu ujian telah habis.']], 423);
        }

        $peserta->refresh();

        return response()->json([
            'status' => 'saved',
            'last_client_seq' => $peserta->last_client_seq,
            'waktu_selesai' => $peserta->waktu_selesai?->toIso8601String(),
        ]);
    }

    private function normalisasiJawaban(PesertaUjian $peserta, int $soalId, ?string $tipe, mixed $jawaban): mixed
    {
        $opsiMap = $peserta->urutan_opsi[$soalId]['options'] ?? [];
        if ($opsiMap === []) {
            return $jawaban;
        }

        if ($tipe === 'pg') {
            return $opsiMap[$jawaban]['answer'] ?? $jawaban;
        }

        if ($tipe === 'pg_kompleks' && is_array($jawaban)) {
            return array_map(fn ($opsiId) => $opsiMap[$opsiId]['answer'] ?? $opsiId, $jawaban);
        }

        if ($tipe === 'benar_salah' && is_array($jawaban)) {
            $normalized = [];
            foreach ($jawaban as $statementId => $value) {
                $statementNumber = $opsiMap[$statementId]['answer'] ?? $statementId;
                $normalized[(string) $statementNumber] = strtoupper((string) $value);
            }

            return $normalized;
        }

        if ($tipe === 'menjodohkan' && is_array($jawaban)) {
            $normalized = [];
            foreach ($jawaban as $kiriId => $kananId) {
                $kiri = $opsiMap[$kiriId]['answer'] ?? $kiriId;
                $kanan = $opsiMap[$kananId]['answer'] ?? $kananId;
                $normalized[$kiri] = $kanan;
            }

            return $normalized;
        }

        return $jawaban;
    }

    private function jawabanUntukSiswa(PesertaUjian $peserta, int $soalId, ?string $tipe, mixed $jawaban): mixed
    {
        $opsiMap = $peserta->urutan_opsi[$soalId]['options'] ?? [];
        if ($opsiMap === []) {
            return $jawaban;
        }

        $jawabanKeId = [];
        foreach ($opsiMap as $opaqueId => $opsi) {
            $jawabanKeId[(string) $opsi['answer']] = $opaqueId;
        }

        if ($tipe === 'pg') {
            return $jawabanKeId[(string) $jawaban] ?? $jawaban;
        }

        if ($tipe === 'pg_kompleks' && is_array($jawaban)) {
            return array_map(fn ($label) => $jawabanKeId[(string) $label] ?? $label, $jawaban);
        }

        if ($tipe === 'benar_salah' && is_array($jawaban)) {
            $normalized = [];
            foreach ($jawaban as $statementNumber => $value) {
                $normalized[$jawabanKeId[(string) $statementNumber] ?? $statementNumber] = $value;
            }

            return $normalized;
        }

        if ($tipe === 'menjodohkan' && is_array($jawaban)) {
            $normalized = [];
            foreach ($jawaban as $left => $right) {
                $normalized[$jawabanKeId[(string) $left] ?? $left] = $jawabanKeId[(string) $right] ?? $right;
            }

            return $normalized;
        }

        return $jawaban;
    }

    /**
     * Submit manual oleh siswa (FR-BE-66).
     */
    public function submit(Request $request): JsonResponse
    {
        $siswa = $request->user()->siswa;
        $peserta = PesertaUjian::where('siswa_id', $siswa?->id)
            ->whereIn('status', ['mengerjakan', 'terkunci'])
            ->latest('id')
            ->first();

        if (! $peserta) {
            return response()->json(['error' => ['code' => 'ALREADY_SUBMITTED', 'message' => 'Ujian sudah diselesaikan.']], 422);
        }

        $this->prosesSubmit($peserta);

        // Siswa HANYA menerima pesan selesai tanpa nilai / skor / pembahasan (FR-BE-69)
        return response()->json([
            'message' => 'Ujian selesai. Terima kasih telah mengerjakan dengan jujur.',
            'status' => 'selesai',
        ]);
    }

    /**
     * Logika internal submit dan hitung nilai otomatis.
     */
    private function prosesSubmit(PesertaUjian $peserta): void
    {
        $submitted = DB::transaction(function () use ($peserta): bool {
            $peserta = PesertaUjian::query()->whereKey($peserta->id)->lockForUpdate()->first();

            if (! $peserta || $peserta->status === 'selesai') {
                return false;
            }

            if (! in_array($peserta->status, ['mengerjakan', 'terkunci'], true)) {
                return false;
            }

            $peserta->update(['status' => 'selesai']);

            return true;
        });

        if ($submitted) {
            ProsesPenilaianPeserta::dispatch($peserta->id);
        }
    }
}
