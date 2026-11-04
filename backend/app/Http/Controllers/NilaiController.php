<?php

namespace App\Http\Controllers;

use App\Models\JadwalUjian;
use App\Models\PesertaUjian;
use App\Services\OtorisasiCbtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NilaiController extends Controller
{
    public function __construct(private readonly OtorisasiCbtService $otorisasi) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jadwal_id' => ['required', 'integer', 'exists:jadwal_ujian,id'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ]);
        $jadwal = JadwalUjian::findOrFail($validated['jadwal_id']);
        $kelasId = isset($validated['kelas_id']) ? (int) $validated['kelas_id'] : null;

        if (! in_array($request->user()->role, ['guru', 'operator', 'superadmin'], true)
            || ($request->user()->role === 'guru' && ! $this->otorisasi->bolehAksesMapelKelas($request->user(), $jadwal, $kelasId))) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berwenang melihat nilai untuk jadwal ini.'],
            ], 403);
        }

        $pesertaQuery = PesertaUjian::query()
            ->with(['siswa.user', 'siswa.kelas', 'nilai'])
            ->whereHas('sesiRuang', fn ($query) => $query->where('jadwal_ujian_id', $jadwal->id));

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

        $rows = $pesertaQuery->orderBy('id')->get()->map(fn (PesertaUjian $peserta): array => [
            'peserta_id' => $peserta->id,
            'nama_siswa' => $peserta->siswa?->user?->name,
            'nis' => $peserta->siswa?->nis,
            'kelas' => $peserta->siswa?->kelas?->nama,
            'status_peserta' => $peserta->status,
            'nilai_status' => $peserta->nilai?->status,
            'total' => $peserta->nilai?->total,
            'maksimal' => $peserta->nilai?->maksimal,
            'nilai_akhir' => $peserta->nilai?->nilai_akhir,
        ]);

        return response()->json(['data' => $rows]);
    }
}
