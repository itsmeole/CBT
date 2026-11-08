<?php

namespace App\Http\Controllers;

use App\Models\JadwalUjian;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Services\AuditLogService;
use App\Services\TokenUjianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Penempatan peserta ke ruang ujian (plotting). Penempatan acak dihitung di frontend lalu dapat
 * disesuaikan manual; server selalu memvalidasi kapasitas, tahun ajaran, dan bentrok jadwal.
 */
class PenempatanController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TokenUjianService $token,
    ) {}

    public function show(int $jadwalId): JsonResponse
    {
        $jadwal = JadwalUjian::with([
            'mapel',
            'sesiRuang' => fn ($sesi) => $sesi->orderBy('sesi_ke')->orderBy('id'),
            'sesiRuang.ruang',
            'sesiRuang.tokenUjian',
            'sesiRuang.pengawasSesi.guru.user:id,name',
            'sesiRuang.pesertaUjian.siswa.user:id,name',
            'sesiRuang.pesertaUjian.siswa.kelas:id,nama',
        ])->findOrFail($jadwalId);

        return response()->json(['data' => [
            'jadwal' => ['id' => $jadwal->id, 'nama' => $jadwal->nama, 'mapel' => $jadwal->mapel?->nama, 'tanggal' => $jadwal->tanggal?->toDateString()],
            'sesi' => $jadwal->sesiRuang->map(fn (SesiRuang $sesi): array => [
                'id' => $sesi->id,
                'ruang' => $sesi->ruang?->nama,
                'kapasitas' => $sesi->ruang?->kapasitas,
                'sesi_ke' => $sesi->sesi_ke,
                'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                'jam_akhir' => substr((string) $sesi->jam_akhir, 0, 5),
                'token' => $sesi->tokenUjian?->kode,
                'pengawas' => $sesi->pengawasSesi->map(fn ($p): array => ['guru_id' => $p->guru_id, 'nama' => $p->guru?->user?->name])->values(),
                'peserta' => $sesi->pesertaUjian->map(fn (PesertaUjian $p): array => [
                    'id' => $p->id,
                    'siswa_id' => $p->siswa_id,
                    'nama' => $p->siswa?->user?->name,
                    'nis' => $p->siswa?->nis,
                    'kelas' => $p->siswa?->kelas?->nama,
                    'status' => $p->status,
                ])->values(),
            ])->values(),
        ]]);
    }

    public function store(Request $request, int $jadwalId): JsonResponse
    {
        $jadwal = JadwalUjian::findOrFail($jadwalId);
        $validated = $request->validate([
            'sesi_ke' => ['required', 'integer', 'min:1', 'max:20'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_akhir' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'jenis' => ['nullable', 'in:reguler,susulan'],
            'penempatan' => ['required', 'array', 'min:1'],
            'penempatan.*.ruang_id' => ['required', 'integer', 'distinct', 'exists:ruang,id'],
            'penempatan.*.siswa_ids' => ['required', 'array', 'min:1'],
            'penempatan.*.siswa_ids.*' => ['required', 'integer', 'exists:siswa,id'],
        ], [
            'jam_akhir.after' => 'Jam akhir harus setelah jam mulai.',
            'penempatan.*.ruang_id.distinct' => 'Satu ruang tidak boleh dipilih dua kali.',
        ]);

        $semuaSiswa = collect($validated['penempatan'])->flatMap(fn (array $baris): array => $baris['siswa_ids']);
        if ($semuaSiswa->count() !== $semuaSiswa->unique()->count()) {
            return $this->error('VALIDATION_ERROR', 'Seorang siswa tidak boleh ditempatkan di dua ruang.', 422);
        }

        $ruangMap = Ruang::whereIn('id', collect($validated['penempatan'])->pluck('ruang_id'))->get()->keyBy('id');
        foreach ($validated['penempatan'] as $baris) {
            $ruang = $ruangMap[$baris['ruang_id']];
            if (count($baris['siswa_ids']) > $ruang->kapasitas) {
                return $this->error('CAPACITY_EXCEEDED', "Ruang {$ruang->nama} berkapasitas {$ruang->kapasitas} tetapi diisi ".count($baris['siswa_ids']).' siswa.', 422);
            }
        }

        $siswaValid = Siswa::whereIn('id', $semuaSiswa)
            ->whereHas('kelas', fn ($kelas) => $kelas->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id))
            ->count();
        if ($siswaValid !== $semuaSiswa->count()) {
            return $this->error('VALIDATION_ERROR', 'Semua peserta harus berasal dari tahun ajaran jadwal ujian.', 422);
        }

        $sudahDitempatkan = PesertaUjian::whereIn('siswa_id', $semuaSiswa)
            ->whereHas('sesiRuang', fn ($sesi) => $sesi->where('jadwal_ujian_id', $jadwal->id))
            ->count();
        if ($sudahDitempatkan > 0) {
            return $this->error('ALREADY_PLACED', "{$sudahDitempatkan} siswa sudah ditempatkan pada jadwal ini. Reset penempatan terlebih dahulu bila ingin mengatur ulang.", 422);
        }

        $bentrokSiswa = $this->siswaBentrok($jadwal, $semuaSiswa->all(), $validated['jam_mulai'], $validated['jam_akhir']);
        if ($bentrokSiswa > 0) {
            return $this->error('SCHEDULE_CONFLICT', "{$bentrokSiswa} siswa sudah punya ujian lain yang waktunya bertabrakan pada tanggal yang sama.", 422);
        }

        foreach ($validated['penempatan'] as $baris) {
            $ruang = $ruangMap[$baris['ruang_id']];
            $sesiAda = SesiRuang::where('jadwal_ujian_id', $jadwal->id)
                ->where('ruang_id', $ruang->id)
                ->where('sesi_ke', $validated['sesi_ke'])
                ->exists();
            if (! $sesiAda && $this->ruangBentrok($jadwal, $ruang->id, $validated['jam_mulai'], $validated['jam_akhir'])) {
                return $this->error('ROOM_CONFLICT', "Ruang {$ruang->nama} sudah dipakai ujian lain pada jam yang bertabrakan.", 422);
            }
        }

        $hasil = DB::transaction(function () use ($validated, $jadwal, $request): array {
            $ringkasan = [];
            foreach ($validated['penempatan'] as $baris) {
                $sesi = SesiRuang::firstOrCreate(
                    ['jadwal_ujian_id' => $jadwal->id, 'ruang_id' => $baris['ruang_id'], 'sesi_ke' => $validated['sesi_ke']],
                    ['jam_mulai' => $validated['jam_mulai'], 'jam_akhir' => $validated['jam_akhir'], 'jenis' => $validated['jenis'] ?? 'reguler'],
                );
                if ($sesi->wasRecentlyCreated) {
                    $this->token->buat($sesi, $request->user());
                }
                foreach ($baris['siswa_ids'] as $siswaId) {
                    PesertaUjian::create(['siswa_id' => $siswaId, 'sesi_ruang_id' => $sesi->id, 'status' => 'belum_login', 'hadir' => false]);
                }
                $ringkasan[] = ['sesi_ruang_id' => $sesi->id, 'ruang_id' => (int) $baris['ruang_id'], 'jumlah_peserta' => count($baris['siswa_ids'])];
            }

            $this->auditLog->record($request->user(), 'jadwal.penempatan_dibuat', 'jadwal_ujian', $jadwal->id, [
                'sesi_ke' => $validated['sesi_ke'],
                'ruang' => count($ringkasan),
                'peserta' => collect($ringkasan)->sum('jumlah_peserta'),
            ], $request);

            return $ringkasan;
        });

        return response()->json(['message' => 'Penempatan peserta berhasil disimpan.', 'data' => $hasil], 201);
    }

    public function destroy(Request $request, int $jadwalId): JsonResponse
    {
        $jadwal = JadwalUjian::findOrFail($jadwalId);
        $sesiIds = SesiRuang::where('jadwal_ujian_id', $jadwal->id)->pluck('id');

        $sudahMulai = PesertaUjian::whereIn('sesi_ruang_id', $sesiIds)
            ->where(fn ($q) => $q->where('status', '!=', 'belum_login')->orWhere('hadir', true))
            ->exists();
        $adaBeritaAcara = SesiRuang::whereIn('id', $sesiIds)->whereHas('beritaAcara')->exists();
        if ($sudahMulai || $adaBeritaAcara) {
            return $this->error('IN_USE', 'Penempatan tidak dapat direset karena ujian sudah berjalan atau kehadiran sudah dicatat.', 422);
        }

        DB::transaction(function () use ($jadwal, $sesiIds, $request): void {
            PesertaUjian::whereIn('sesi_ruang_id', $sesiIds)->delete();
            SesiRuang::whereIn('id', $sesiIds)->delete();
            $this->auditLog->record($request->user(), 'jadwal.penempatan_direset', 'jadwal_ujian', $jadwal->id, ['sesi' => $sesiIds->count()], $request);
        });

        return response()->json(['message' => 'Penempatan peserta dan sesi ruang direset.']);
    }

    /**
     * Jumlah siswa yang sudah punya ujian lain bertabrakan waktu pada tanggal yang sama.
     *
     * @param  array<int, int>  $siswaIds
     */
    private function siswaBentrok(JadwalUjian $jadwal, array $siswaIds, string $mulai, string $akhir): int
    {
        return PesertaUjian::whereIn('siswa_id', $siswaIds)
            ->whereHas('sesiRuang', fn ($sesi) => $this->overlap($sesi, $jadwal, $mulai, $akhir))
            ->distinct()
            ->count('siswa_id');
    }

    private function ruangBentrok(JadwalUjian $jadwal, int $ruangId, string $mulai, string $akhir): bool
    {
        return SesiRuang::where('ruang_id', $ruangId)
            ->where(fn ($sesi) => $this->overlap($sesi, $jadwal, $mulai, $akhir))
            ->exists();
    }

    private function overlap($query, JadwalUjian $jadwal, string $mulai, string $akhir)
    {
        return $query->where('jadwal_ujian_id', '!=', $jadwal->id)
            ->whereHas('jadwalUjian', fn ($j) => $j->whereDate('tanggal', $jadwal->tanggal))
            ->where('jam_mulai', '<', $akhir.':00')
            ->where('jam_akhir', '>', $mulai.':00');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
