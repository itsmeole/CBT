<?php

namespace App\Http\Controllers;

use App\Models\BobotTipe;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\PengawasSesi;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\TokenUjian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JadwalController extends Controller
{
    // ==========================================
    // BOBOT TIPE SOAL (Operator & Superadmin)
    // ==========================================
    public function getBobotTipe(): JsonResponse
    {
        return response()->json(['data' => BobotTipe::all()]);
    }

    public function updateBobotTipe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bobot' => 'required|array',
            'bobot.*' => 'required|numeric|min:0.1',
        ]);

        foreach ($validated['bobot'] as $tipe => $nilai) {
            BobotTipe::updateOrCreate(['tipe' => $tipe], ['bobot' => $nilai]);
        }

        return response()->json(['message' => 'Bobot tipe soal berhasil diperbarui.', 'data' => BobotTipe::all()]);
    }

    // ==========================================
    // PAKET SOAL (Guru mapel & Superadmin)
    // ==========================================
    public function getPaketSoal(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PaketSoal::with(['mapel', 'guru.user', 'items.bankSoal']);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            $query->whereIn('mapel_id', $mapelIds);
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function storePaketSoal(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'nama' => 'required|string|max:100',
            'deskripsi' => 'nullable|string',
            'soal_ids' => 'required|array|min:1',
            'soal_ids.*' => 'exists:bank_soal,id',
        ]);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($validated['mapel_id'])) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke mapel ini.']], 403);
            }
            $guruId = $user->guru->id;
        } else {
            $guruId = $request->input('guru_id', $user->guru?->id ?? 1);
        }

        return DB::transaction(function () use ($validated, $guruId) {
            $paket = PaketSoal::create([
                'mapel_id' => $validated['mapel_id'],
                'guru_id' => $guruId,
                'nama' => $validated['nama'],
                'deskripsi' => $validated['deskripsi'] ?? null,
            ]);

            foreach ($validated['soal_ids'] as $idx => $soalId) {
                PaketSoalItem::create([
                    'paket_soal_id' => $paket->id,
                    'bank_soal_id' => $soalId,
                    'urutan' => $idx + 1,
                ]);
            }

            return response()->json([
                'data' => $paket->load(['items.bankSoal', 'mapel']),
                'message' => 'Paket soal berhasil dibuat.',
            ], 201);
        });
    }

    public function destroyPaketSoal(Request $request, int $id): JsonResponse
    {
        $paket = PaketSoal::findOrFail($id);

        if ($paket->jadwalUjian()->exists()) {
            return response()->json(['error' => ['code' => 'IN_USE', 'message' => 'Paket soal sudah digunakan di jadwal ujian dan tidak dapat dihapus.']], 422);
        }

        $paket->delete();
        return response()->json(['message' => 'Paket soal berhasil dihapus.']);
    }

    // ==========================================
    // JADWAL UJIAN (Operator & Superadmin)
    // ==========================================
    public function getJadwal(): JsonResponse
    {
        return response()->json([
            'data' => JadwalUjian::with(['mapel', 'paketSoal.items', 'tahunAjaran', 'sesiRuang.ruang', 'sesiRuang.tokenUjian'])->latest()->get()
        ]);
    }

    public function storeJadwal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'paket_soal_id' => 'required|exists:paket_soal,id',
            'tahun_ajaran_id' => 'nullable|exists:tahun_ajaran,id',
            'nama' => 'required|string|max:100',
            'tanggal' => 'required|date',
            'durasi_menit' => 'required|integer|min:1',
        ]);

        $taId = $validated['tahun_ajaran_id'] ?? TahunAjaran::where('aktif', true)->value('id');
        if (!$taId) {
            return response()->json(['error' => ['code' => 'NO_ACTIVE_ACADEMIC_YEAR', 'message' => 'Tidak ada tahun ajaran yang aktif.']], 422);
        }

        // FR-BE-42: Snapshot bobot saat jadwal dibuat!
        $bobotMap = BobotTipe::all()->pluck('bobot', 'tipe')->toArray();

        $jadwal = JadwalUjian::create([
            'mapel_id' => $validated['mapel_id'],
            'paket_soal_id' => $validated['paket_soal_id'],
            'tahun_ajaran_id' => $taId,
            'nama' => $validated['nama'],
            'tanggal' => $validated['tanggal'],
            'durasi_menit' => $validated['durasi_menit'],
            'bobot_snapshot' => $bobotMap,
            'status' => 'aktif',
        ]);

        return response()->json([
            'data' => $jadwal->load(['mapel', 'paketSoal', 'tahunAjaran']),
            'message' => 'Jadwal ujian berhasil dibuat.',
        ], 201);
    }

    // ==========================================
    // SESI RUANG & PLOTTING (Operator & Superadmin)
    // ==========================================
    public function storeSesiRuang(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jadwal_ujian_id' => 'required|exists:jadwal_ujian,id',
            'ruang_id' => 'required|exists:ruang,id',
            'sesi_ke' => 'required|integer|min:1',
            'jam_mulai' => 'required',
            'jam_akhir' => 'required',
            'jenis' => 'nullable|in:reguler,susulan',
            'siswa_ids' => 'nullable|array',
            'siswa_ids.*' => 'exists:siswa,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $sesi = SesiRuang::create([
                'jadwal_ujian_id' => $validated['jadwal_ujian_id'],
                'ruang_id' => $validated['ruang_id'],
                'sesi_ke' => $validated['sesi_ke'],
                'jam_mulai' => $validated['jam_mulai'],
                'jam_akhir' => $validated['jam_akhir'],
                'jenis' => $validated['jenis'] ?? 'reguler',
            ]);

            // Buat token otomatis
            TokenUjian::create([
                'sesi_ruang_id' => $sesi->id,
                'kode' => $this->generateUniqueToken(),
                'aktif' => true,
                'dibuat_oleh' => $request->user()->id,
            ]);

            // Petakan siswa ke peserta_ujian jika diberikan
            if (!empty($validated['siswa_ids'])) {
                foreach ($validated['siswa_ids'] as $siswaId) {
                    PesertaUjian::updateOrCreate(
                        ['siswa_id' => $siswaId, 'sesi_ruang_id' => $sesi->id],
                        ['status' => 'belum_login', 'hadir' => false]
                    );
                }
            }

            return response()->json([
                'data' => $sesi->load(['ruang', 'tokenUjian', 'jadwalUjian']),
                'message' => 'Sesi ruang ujian berhasil dibuat.',
            ], 201);
        });
    }

    public function plotPengawas(Request $request, int $sesiRuangId): JsonResponse
    {
        $validated = $request->validate([
            'guru_ids' => 'required|array',
            'guru_ids.*' => 'exists:guru,id',
        ]);

        $sesi = SesiRuang::findOrFail($sesiRuangId);

        PengawasSesi::where('sesi_ruang_id', $sesi->id)->delete();
        foreach ($validated['guru_ids'] as $guruId) {
            PengawasSesi::create([
                'sesi_ruang_id' => $sesi->id,
                'guru_id' => $guruId,
            ]);
        }

        return response()->json(['message' => 'Pengawas berhasil diplot ke sesi ini.']);
    }

    public function generateToken(Request $request, int $sesiRuangId): JsonResponse
    {
        $sesi = SesiRuang::findOrFail($sesiRuangId);

        // Nonaktifkan token lama
        TokenUjian::where('sesi_ruang_id', $sesi->id)->update(['aktif' => false]);

        $token = TokenUjian::create([
            'sesi_ruang_id' => $sesi->id,
            'kode' => $this->generateUniqueToken(),
            'aktif' => true,
            'dibuat_oleh' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $token,
            'message' => 'Token ujian baru berhasil digenerate.',
        ]);
    }

    /**
     * Generate 6 karakter huruf kapital tanpa karakter ambigu (0, O, 1, I).
     */
    private function generateUniqueToken(): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $token = '';
            for ($i = 0; $i < 6; $i++) {
                $token .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (TokenUjian::where('kode', $token)->where('aktif', true)->exists());

        return $token;
    }
}

