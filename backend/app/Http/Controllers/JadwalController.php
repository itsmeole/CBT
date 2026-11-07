<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
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
use App\Services\OtorisasiCbtService;
use App\Services\TokenUjianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JadwalController extends Controller
{
    public function __construct(private readonly OtorisasiCbtService $otorisasi) {}

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
            $query->where('guru_id', $user->guru?->id ?? 0)
                ->whereIn('mapel_id', $mapelIds)
                ->whereDoesntHave('items', fn ($items) => $items->whereHas(
                    'bankSoal',
                    fn ($soal) => $soal->where('guru_id', '!=', $user->guru?->id ?? 0)
                        ->orWhereColumn('bank_soal.mapel_id', '!=', 'paket_soal.mapel_id'),
                ));
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
            'soal_ids.*' => 'required|distinct|exists:bank_soal,id',
        ]);

        $soalQuery = BankSoal::query()->whereIn('id', $validated['soal_ids']);
        if ($user->role === 'guru') {
            $soalQuery->where('guru_id', $user->guru?->id ?? 0);
        }
        $accessibleSoalCount = (clone $soalQuery)->count();
        $soalMapelIds = $soalQuery->pluck('mapel_id')->unique();

        if ($user->role === 'guru' && $accessibleSoalCount !== count($validated['soal_ids'])) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Paket hanya dapat menggunakan soal milik Anda.']], 403);
        }

        if ($soalMapelIds->count() !== 1 || (int) $soalMapelIds->first() !== (int) $validated['mapel_id']) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Semua soal dalam paket harus berasal dari mapel yang dipilih.']], 422);
        }

        if ($user->role === 'guru') {
            if (! $this->otorisasi->bolehKelolaMapel($user, (int) $validated['mapel_id'])) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke mapel ini.']], 403);
            }
            $guruId = $user->guru->id;
        } else {
            // Operator/Superadmin tidak punya data guru. Pemilik paket diambil dari guru pemilik soal
            // (atau dari `guru_id` bila dikirim eksplisit), sehingga form tidak perlu memintanya.
            $request->validate(['guru_id' => 'nullable|exists:guru,id']);
            $guruId = $request->filled('guru_id')
                ? (int) $request->input('guru_id')
                : (int) BankSoal::query()->whereIn('id', $validated['soal_ids'])->orderBy('id')->value('guru_id');
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

        if ($request->user()->role === 'guru'
            && ((int) $paket->guru_id !== (int) $request->user()->guru?->id
                || ! $this->otorisasi->bolehKelolaMapel($request->user(), (int) $paket->mapel_id))) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke paket soal ini.']], 403);
        }

        if ($paket->jadwalUjian()->exists()) {
            return response()->json(['error' => ['code' => 'IN_USE', 'message' => 'Paket soal sudah digunakan di jadwal ujian dan tidak dapat dihapus.']], 422);
        }

        $paket->delete();

        return response()->json(['message' => 'Paket soal berhasil dihapus.']);
    }

    // ==========================================
    // JADWAL UJIAN (Operator & Superadmin)
    // ==========================================
    public function getJadwal(Request $request): JsonResponse
    {
        $query = JadwalUjian::with([
            'mapel',
            'paketSoal.items',
            'tahunAjaran',
            'sesiRuang' => fn ($sesi) => $sesi->withCount('pesertaUjian')->orderBy('sesi_ke')->orderBy('id'),
            'sesiRuang.ruang',
            'sesiRuang.tokenUjian',
            'sesiRuang.pengawasSesi.guru.user:id,name',
        ])->latest();

        $user = $request->user();
        if ($user->role === 'guru') {
            $query->whereIn('mapel_id', GuruMapel::where('guru_id', $user->guru?->id ?? 0)->select('mapel_id'));
        }

        return response()->json(['data' => $query->get()]);
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

        $mapelId = (int) $validated['mapel_id'];
        $paket = PaketSoal::with('items.bankSoal')->findOrFail((int) $validated['paket_soal_id']);
        $items = $paket->items;

        if ((int) $paket->mapel_id !== $mapelId || $items->isEmpty()) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Paket soal harus berisi soal dan berasal dari mapel yang dipilih.']], 422);
        }

        if ($items->contains(fn (PaketSoalItem $item): bool => $item->bankSoal === null)) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Paket soal memuat soal yang sudah tidak tersedia.']], 422);
        }

        if ($items->contains(fn (PaketSoalItem $item): bool => (int) ($item->bankSoal?->mapel_id ?? 0) !== $mapelId)) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Paket soal memuat soal dari mapel lain.']], 422);
        }

        $taId = $validated['tahun_ajaran_id'] ?? TahunAjaran::where('aktif', true)->value('id');
        if (! $taId) {
            return response()->json(['error' => ['code' => 'NO_ACTIVE_ACADEMIC_YEAR', 'message' => 'Tidak ada tahun ajaran yang aktif.']], 422);
        }

        // FR-BE-42: Snapshot bobot saat jadwal dibuat!
        $bobotMap = BobotTipe::all()->pluck('bobot', 'tipe')->toArray();
        $soalSnapshot = $items->map(fn (PaketSoalItem $item): int => (int) $item->bank_soal_id)->all();

        $jadwal = JadwalUjian::create([
            'mapel_id' => $validated['mapel_id'],
            'paket_soal_id' => $validated['paket_soal_id'],
            'tahun_ajaran_id' => $taId,
            'nama' => $validated['nama'],
            'tanggal' => $validated['tanggal'],
            'durasi_menit' => $validated['durasi_menit'],
            'bobot_snapshot' => $bobotMap,
            'soal_snapshot' => $soalSnapshot,
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
            'siswa_ids' => 'required|array|min:1',
            'siswa_ids.*' => 'required|distinct|exists:siswa,id',
        ]);

        $jadwal = JadwalUjian::findOrFail($validated['jadwal_ujian_id']);
        $jumlahSiswaValid = Siswa::whereIn('id', $validated['siswa_ids'])
            ->whereHas('kelas', fn ($query) => $query->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id))
            ->count();

        if ($jumlahSiswaValid !== count($validated['siswa_ids'])) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Semua peserta harus berasal dari tahun ajaran jadwal ujian.']], 422);
        }

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
            if (! empty($validated['siswa_ids'])) {
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

        $sesi = SesiRuang::with('jadwalUjian')->findOrFail($sesiRuangId);

        // Seorang guru tidak boleh mengawas dua ruang pada tanggal dan jam yang bertabrakan.
        $bentrok = PengawasSesi::query()
            ->with(['guru.user:id,name', 'sesiRuang.ruang', 'sesiRuang.jadwalUjian.mapel'])
            ->whereIn('guru_id', $validated['guru_ids'])
            ->where('sesi_ruang_id', '!=', $sesi->id)
            ->whereHas('sesiRuang', fn ($lain) => $lain
                ->whereHas('jadwalUjian', fn ($j) => $j->whereDate('tanggal', $sesi->jadwalUjian->tanggal))
                ->where('jam_mulai', '<', $sesi->jam_akhir)
                ->where('jam_akhir', '>', $sesi->jam_mulai))
            ->first();
        if ($bentrok) {
            $lain = $bentrok->sesiRuang;

            return response()->json(['error' => [
                'code' => 'SUPERVISOR_CONFLICT',
                'message' => 'Pengawas '.($bentrok->guru?->user?->name ?? '-').' sudah bertugas di '.($lain->ruang?->nama ?? 'ruang lain')
                    .' ('.($lain->jadwalUjian?->mapel?->nama ?? '-').', '.substr((string) $lain->jam_mulai, 0, 5).'-'.substr((string) $lain->jam_akhir, 0, 5).') pada waktu yang bertabrakan.',
            ]], 422);
        }

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
        return app(TokenUjianService::class)->kodeUnik();
    }
}
