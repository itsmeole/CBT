<?php

namespace App\Http\Controllers;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MasterDataController extends Controller
{
    // ==========================================
    // TAHUN AJARAN
    // ==========================================
    public function getTahunAjaran(): JsonResponse
    {
        return response()->json(['data' => TahunAjaran::orderByDesc('id')->get()]);
    }

    public function storeTahunAjaran(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:50',
            'semester' => 'required|integer|in:1,2',
            'aktif' => 'boolean',
        ]);

        if (!empty($validated['aktif'])) {
            TahunAjaran::query()->update(['aktif' => false]);
        }

        $ta = TahunAjaran::create($validated);
        return response()->json(['data' => $ta, 'message' => 'Tahun ajaran berhasil ditambahkan.'], 201);
    }

    public function updateTahunAjaran(Request $request, int $id): JsonResponse
    {
        $ta = TahunAjaran::findOrFail($id);
        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:50',
            'semester' => 'sometimes|required|integer|in:1,2',
            'aktif' => 'boolean',
        ]);

        if (!empty($validated['aktif'])) {
            TahunAjaran::where('id', '!=', $id)->update(['aktif' => false]);
        }

        $ta->update($validated);
        return response()->json(['data' => $ta, 'message' => 'Tahun ajaran berhasil diperbarui.']);
    }

    public function destroyTahunAjaran(int $id): JsonResponse
    {
        $ta = TahunAjaran::findOrFail($id);
        $ta->delete();
        return response()->json(['message' => 'Tahun ajaran berhasil dihapus.']);
    }

    // ==========================================
    // KELAS
    // ==========================================
    public function getKelas(): JsonResponse
    {
        return response()->json(['data' => Kelas::with('tahunAjaran')->orderBy('nama')->get()]);
    }

    public function storeKelas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:50',
            'tingkat' => 'required|string|max:20',
            'tahun_ajaran_id' => 'required|exists:tahun_ajaran,id',
        ]);

        $kelas = Kelas::create($validated);
        return response()->json(['data' => $kelas->load('tahunAjaran'), 'message' => 'Kelas berhasil ditambahkan.'], 201);
    }

    public function updateKelas(Request $request, int $id): JsonResponse
    {
        $kelas = Kelas::findOrFail($id);
        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:50',
            'tingkat' => 'sometimes|required|string|max:20',
            'tahun_ajaran_id' => 'sometimes|required|exists:tahun_ajaran,id',
        ]);

        $kelas->update($validated);
        return response()->json(['data' => $kelas->load('tahunAjaran'), 'message' => 'Kelas berhasil diperbarui.']);
    }

    public function destroyKelas(int $id): JsonResponse
    {
        $kelas = Kelas::findOrFail($id);
        $kelas->delete();
        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }

    // ==========================================
    // SISWA
    // ==========================================
    public function getSiswa(): JsonResponse
    {
        return response()->json(['data' => Siswa::with(['user', 'kelas.tahunAjaran'])->get()]);
    }

    public function storeSiswa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'nis' => 'required|string|max:30|unique:siswa,nis',
            'kelas_id' => 'required|exists:kelas,id',
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'role' => 'siswa',
            'aktif' => true,
        ]);

        $siswa = Siswa::create([
            'user_id' => $user->id,
            'nis' => $validated['nis'],
            'kelas_id' => $validated['kelas_id'],
        ]);

        return response()->json(['data' => $siswa->load(['user', 'kelas']), 'message' => 'Siswa berhasil ditambahkan.'], 201);
    }

    public function updateSiswa(Request $request, int $id): JsonResponse
    {
        $siswa = Siswa::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'password' => 'nullable|string|min:6',
            'nis' => 'sometimes|required|string|max:30|unique:siswa,nis,' . $siswa->id,
            'kelas_id' => 'sometimes|required|exists:kelas,id',
        ]);

        if (isset($validated['name'])) {
            $siswa->user->name = $validated['name'];
        }
        if (!empty($validated['password'])) {
            $siswa->user->password = Hash::make($validated['password']);
        }
        $siswa->user->save();

        $siswa->update($validated);
        return response()->json(['data' => $siswa->load(['user', 'kelas']), 'message' => 'Siswa berhasil diperbarui.']);
    }

    public function destroySiswa(int $id): JsonResponse
    {
        $siswa = Siswa::findOrFail($id);
        $user = $siswa->user;
        $siswa->delete();
        if ($user) $user->delete();
        return response()->json(['message' => 'Siswa berhasil dihapus.']);
    }

    // ==========================================
    // GURU
    // ==========================================
    public function getGuru(): JsonResponse
    {
        return response()->json(['data' => Guru::with('user')->get()]);
    }

    public function storeGuru(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'nip' => 'nullable|string|max:50|unique:guru,nip',
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'role' => 'guru',
            'aktif' => true,
        ]);

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => $validated['nip'] ?? null,
        ]);

        return response()->json(['data' => $guru->load('user'), 'message' => 'Guru berhasil ditambahkan.'], 201);
    }

    public function updateGuru(Request $request, int $id): JsonResponse
    {
        $guru = Guru::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'password' => 'nullable|string|min:6',
            'nip' => 'nullable|string|max:50|unique:guru,nip,' . $guru->id,
        ]);

        if (isset($validated['name'])) {
            $guru->user->name = $validated['name'];
        }
        if (!empty($validated['password'])) {
            $guru->user->password = Hash::make($validated['password']);
        }
        $guru->user->save();

        $guru->update($validated);
        return response()->json(['data' => $guru->load('user'), 'message' => 'Guru berhasil diperbarui.']);
    }

    public function destroyGuru(int $id): JsonResponse
    {
        $guru = Guru::findOrFail($id);
        $user = $guru->user;
        $guru->delete();
        if ($user) $user->delete();
        return response()->json(['message' => 'Guru berhasil dihapus.']);
    }

    // ==========================================
    // MAPEL
    // ==========================================
    public function getMapel(): JsonResponse
    {
        return response()->json(['data' => Mapel::orderBy('nama')->get()]);
    }

    public function storeMapel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:30|unique:mapel,kode',
            'nama' => 'required|string|max:100',
        ]);

        $mapel = Mapel::create($validated);
        return response()->json(['data' => $mapel, 'message' => 'Mata pelajaran berhasil ditambahkan.'], 201);
    }

    public function updateMapel(Request $request, int $id): JsonResponse
    {
        $mapel = Mapel::findOrFail($id);
        $validated = $request->validate([
            'kode' => 'sometimes|required|string|max:30|unique:mapel,kode,' . $mapel->id,
            'nama' => 'sometimes|required|string|max:100',
        ]);

        $mapel->update($validated);
        return response()->json(['data' => $mapel, 'message' => 'Mata pelajaran berhasil diperbarui.']);
    }

    public function destroyMapel(int $id): JsonResponse
    {
        $mapel = Mapel::findOrFail($id);
        $mapel->delete();
        return response()->json(['message' => 'Mata pelajaran berhasil dihapus.']);
    }

    // ==========================================
    // GURU MAPEL
    // ==========================================
    public function getGuruMapel(): JsonResponse
    {
        return response()->json([
            'data' => GuruMapel::with(['guru.user', 'mapel', 'kelas', 'tahunAjaran'])->get()
        ]);
    }

    public function storeGuruMapel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guru_id' => 'required|exists:guru,id',
            'mapel_id' => 'required|exists:mapel,id',
            'kelas_id' => 'required|exists:kelas,id',
            'tahun_ajaran_id' => 'required|exists:tahun_ajaran,id',
        ]);

        $gm = GuruMapel::updateOrCreate($validated, $validated);
        return response()->json([
            'data' => $gm->load(['guru.user', 'mapel', 'kelas', 'tahunAjaran']),
            'message' => 'Pengampu mapel berhasil diatur.'
        ], 201);
    }

    public function destroyGuruMapel(int $id): JsonResponse
    {
        $gm = GuruMapel::findOrFail($id);
        $gm->delete();
        return response()->json(['message' => 'Pengampu mapel berhasil dihapus.']);
    }

    // ==========================================
    // RUANG
    // ==========================================
    public function getRuang(): JsonResponse
    {
        return response()->json(['data' => Ruang::orderBy('nama')->get()]);
    }

    public function storeRuang(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:50',
            'kapasitas' => 'required|integer|min:1',
        ]);

        $ruang = Ruang::create($validated);
        return response()->json(['data' => $ruang, 'message' => 'Ruang ujian berhasil ditambahkan.'], 201);
    }

    public function updateRuang(Request $request, int $id): JsonResponse
    {
        $ruang = Ruang::findOrFail($id);
        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:50',
            'kapasitas' => 'sometimes|required|integer|min:1',
        ]);

        $ruang->update($validated);
        return response()->json(['data' => $ruang, 'message' => 'Ruang ujian berhasil diperbarui.']);
    }

    public function destroyRuang(int $id): JsonResponse
    {
        $ruang = Ruang::findOrFail($id);
        $ruang->delete();
        return response()->json(['message' => 'Ruang ujian berhasil dihapus.']);
    }
}

