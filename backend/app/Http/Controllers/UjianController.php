<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\JawabanSiswa;
use App\Models\PesertaUjian;
use App\Models\TokenUjian;
use App\Services\PenilaianService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UjianController extends Controller
{
    protected PenilaianService $penilaianService;

    public function __construct(PenilaianService $penilaianService)
    {
        $this->penilaianService = $penilaianService;
    }

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

        if (!$token) {
            return response()->json(['error' => ['code' => 'TOKEN_INVALID', 'message' => 'Token ujian tidak valid atau sudah kadaluarsa.']], 422);
        }

        $sesi = $token->sesiRuang;
        $siswa = $request->user()->siswa;

        if (!$siswa) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya akun siswa yang dapat mengikuti ujian.']], 403);
        }

        // Cek / daftarkan siswa ke sesi_ruang ini
        $peserta = PesertaUjian::firstOrCreate(
            ['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesi->id],
            ['status' => 'belum_login', 'hadir' => false]
        );

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
        if (!$siswa) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Bukan akun siswa.']], 403);
        }

        $peserta = PesertaUjian::with(['sesiRuang.jadwalUjian.mapel', 'sesiRuang.ruang'])
            ->where('siswa_id', $siswa->id)
            ->whereIn('status', ['menunggu', 'mengerjakan', 'terkunci'])
            ->latest('id')
            ->first();

        if (!$peserta) {
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

        // Ambil jawaban yang tersimpan
        $jawabanMap = $peserta->jawaban()->pluck('jawaban', 'bank_soal_id')->toArray();

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

        if (!$peserta) {
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
            if (!$soal) continue;

            $opsiFormatted = [];
            $opsiOrderedIds = $urutanOpsiMap[$soalId] ?? [];

            if ($soal->tipe === 'menjodohkan' && is_array($opsiOrderedIds)) {
                $kiriIds = $opsiOrderedIds['kiri'] ?? [];
                $kananIds = $opsiOrderedIds['kanan'] ?? [];

                $kiriItems = $soal->opsiSoal->whereIn('id', $kiriIds)->keyBy('id');
                $kananItems = $soal->opsiSoal->whereIn('id', $kananIds)->keyBy('id');

                $opsiFormatted = [
                    'kiri' => array_values(array_filter(array_map(fn($id) => $kiriItems->get($id) ? ['id' => $id, 'konten' => $kiriItems->get($id)->konten] : null, $kiriIds))),
                    'kanan' => array_values(array_filter(array_map(fn($id) => $kananItems->get($id) ? ['id' => $id, 'konten' => $kananItems->get($id)->konten] : null, $kananIds))),
                ];
            } else {
                $opsiKeyed = $soal->opsiSoal->keyBy('id');
                if (is_array($opsiOrderedIds) && !empty($opsiOrderedIds)) {
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

    /**
     * Autosave jawaban delta per nomor / batch dengan nomor sequence client_seq (FR-BE-64).
     */
    public function autosave(Request $request): JsonResponse
    {
        $siswa = $request->user()->siswa;
        $peserta = PesertaUjian::where('siswa_id', $siswa?->id)->latest('id')->first();

        if (!$peserta) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Peserta tidak ditemukan.']], 404);
        }

        if ($peserta->status === 'terkunci') {
            return response()->json(['error' => ['code' => 'SESSION_LOCKED', 'message' => 'Ujian terkunci, hubungi pengawas.']], 423);
        }

        if ($peserta->status === 'selesai') {
            return response()->json(['error' => ['code' => 'ALREADY_SUBMITTED', 'message' => 'Ujian sudah diselesaikan.']], 423);
        }

        if ($peserta->status !== 'mengerjakan') {
            return response()->json(['error' => ['code' => 'NOT_STARTED', 'message' => 'Ujian belum dimulai.']], 403);
        }

        // Cek batas waktu server
        if ($peserta->waktu_selesai && Carbon::now()->greaterThanOrEqualTo($peserta->waktu_selesai)) {
            $this->prosesSubmit($peserta);
            return response()->json(['error' => ['code' => 'TIME_UP', 'message' => 'Waktu ujian telah habis.']], 423);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.soal_id' => 'required|exists:bank_soal,id',
            'items.*.jawaban' => 'nullable',
            'items.*.client_seq' => 'required|integer',
        ]);

        DB::transaction(function () use ($peserta, $validated) {
            foreach ($validated['items'] as $item) {
                $clientSeq = (int) $item['client_seq'];

                // Abaikan jika client_seq lebih kecil dari yang sudah tersimpan (data basi)
                $existing = JawabanSiswa::where('peserta_ujian_id', $peserta->id)
                    ->where('bank_soal_id', $item['soal_id'])
                    ->first();

                if ($existing && $existing->client_seq >= $clientSeq) {
                    continue;
                }

                JawabanSiswa::updateOrCreate(
                    ['peserta_ujian_id' => $peserta->id, 'bank_soal_id' => $item['soal_id']],
                    ['jawaban' => $item['jawaban'], 'client_seq' => $clientSeq]
                );

                if ($clientSeq > $peserta->last_client_seq) {
                    $peserta->last_client_seq = $clientSeq;
                }
            }
            $peserta->save();
        });

        return response()->json([
            'status' => 'saved',
            'last_client_seq' => $peserta->last_client_seq,
        ]);
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

        if (!$peserta) {
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
        DB::transaction(function () use ($peserta) {
            $peserta->update(['status' => 'selesai']);
            $this->penilaianService->nilaiPeserta($peserta);
        });
    }
}

