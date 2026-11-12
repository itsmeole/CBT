<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageUserRequest;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MasterDataController extends Controller
{
    public function indexUsers(): JsonResponse
    {
        return response()->json(['data' => User::with(['siswa.kelas', 'guru'])->orderBy('id')->get()]);
    }

    public function storeUser(ManageUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'username' => $validated['username'],
                'name' => $validated['name'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'aktif' => $validated['aktif'] ?? true,
            ]);

            if ($user->role === 'siswa') {
                $user->siswa()->create([
                    'nis' => $validated['nis'],
                    'kelas_id' => $validated['kelas_id'],
                ]);
            } elseif ($user->role === 'guru') {
                $user->guru()->create(['nip' => $validated['nip'] ?? null]);
            }

            return $user;
        });

        return response()->json([
            'data' => $user->load(['siswa.kelas', 'guru']),
            'message' => 'Akun berhasil dibuat.',
        ], 201);
    }

    public function updateUser(ManageUserRequest $request, int $id): JsonResponse
    {
        $user = User::with(['siswa', 'guru'])->findOrFail($id);
        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated): void {
            $userFields = array_intersect_key($validated, array_flip(['username', 'name', 'aktif', 'password']));
            if (empty($userFields['password'])) {
                unset($userFields['password']);
            }
            $user->update($userFields);

            if ($user->role === 'siswa' && $user->siswa) {
                $user->siswa->update(array_intersect_key($validated, array_flip(['nis', 'kelas_id'])));
            } elseif ($user->role === 'guru' && $user->guru && array_key_exists('nip', $validated)) {
                $user->guru->update(['nip' => $validated['nip']]);
            }
        });

        return response()->json([
            'data' => $user->fresh()->load(['siswa.kelas', 'guru']),
            'message' => 'Akun berhasil diperbarui.',
        ]);
    }

    public function destroyUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->is($request->user())) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.']], 422);
        }

        $this->nonaktifkanAkun($user);

        return response()->json(['message' => 'Akun berhasil dinonaktifkan.']);
    }

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

        if (! empty($validated['aktif'])) {
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

        if (! empty($validated['aktif'])) {
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
    /**
     * Guru hanya melihat kelas yang diampunya; Operator/Superadmin melihat semua kelas.
     */
    public function getKelas(Request $request): JsonResponse
    {
        $query = Kelas::with('tahunAjaran')->orderBy('nama');
        $user = $request->user();
        if ($user->role === 'guru') {
            $query->whereIn('id', GuruMapel::where('guru_id', $user->guru?->id ?? 0)->whereNotNull('kelas_id')->select('kelas_id'));
        }

        return response()->json(['data' => $query->get()]);
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
    /**
     * Tanpa parameter `page`: seluruh siswa (dipakai penempatan peserta). Dengan `page`: paginasi di server
     * dengan pencarian `q` (nama, username, NIS) dan filter `kelas_id`.
     */
    public function getSiswa(Request $request): JsonResponse
    {
        $query = Siswa::with(['user', 'kelas.tahunAjaran']);
        if (! $request->has('page')) {
            return response()->json(['data' => $query->get()]);
        }

        $kelasId = $request->integer('kelas_id');
        $q = trim((string) $request->query('q', ''));
        $query->when($kelasId > 0, fn ($builder) => $builder->where('kelas_id', $kelasId))
            ->when($q !== '', fn ($builder) => $builder->where(fn ($cari) => $cari
                ->where('nis', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%"))))
            ->orderBy('kelas_id')->orderBy('id');

        return $this->halaman($query, $request);
    }

    /** Respons paginasi seragam: data + meta. */
    private function halaman($query, Request $request): JsonResponse
    {
        $perHalaman = max(1, min(100, $request->integer('per_page', 25)));
        $hasil = $query->paginate($perHalaman, ['*'], 'page', max(1, $request->integer('page', 1)));

        return response()->json([
            'data' => $hasil->items(),
            'meta' => ['page' => $hasil->currentPage(), 'per_page' => $hasil->perPage(), 'total' => $hasil->total(), 'last_page' => $hasil->lastPage()],
        ]);
    }

    public function storeSiswa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'nis' => 'required|string|max:30|unique:siswa,nis',
            'kelas_id' => 'required|exists:kelas,id',
            'jk' => 'nullable|string|in:L,P',
        ]);

        $siswa = DB::transaction(function () use ($validated): Siswa {
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
                'jk' => $validated['jk'] ?? 'L',
            ]);

            return $siswa;
        });

        return response()->json(['data' => $siswa->load(['user', 'kelas']), 'message' => 'Siswa berhasil ditambahkan.'], 201);
    }

    public function updateSiswa(Request $request, int $id): JsonResponse
    {
        $siswa = Siswa::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|max:100|unique:users,username,'.$siswa->user_id,
            'password' => 'nullable|string|min:6',
            'nis' => 'sometimes|required|string|max:30|unique:siswa,nis,'.$siswa->id,
            'kelas_id' => 'sometimes|required|exists:kelas,id',
            'jk' => 'nullable|string|in:L,P',
        ]);

        if (isset($validated['name'])) {
            $siswa->user->name = $validated['name'];
        }
        if (isset($validated['username'])) {
            $siswa->user->username = $validated['username'];
        }
        if (! empty($validated['password'])) {
            $siswa->user->password = Hash::make($validated['password']);
        }
        $siswa->user->save();

        $siswaData = array_intersect_key($validated, array_flip(['nis', 'kelas_id', 'jk']));
        if (! empty($siswaData)) {
            $siswa->update($siswaData);
        }

        return response()->json(['data' => $siswa->load(['user', 'kelas']), 'message' => 'Siswa berhasil diperbarui.']);
    }

    public function destroySiswa(int $id): JsonResponse
    {
        $siswa = Siswa::findOrFail($id);
        $user = $siswa->user;
        DB::transaction(function () use ($siswa, $user): void {
            $siswa->delete();
            if ($user) {
                $this->nonaktifkanAkun($user);
            }
        });

        return response()->json(['message' => 'Siswa berhasil dinonaktifkan.']);
    }

    // ==========================================
    // GURU
    // ==========================================
    /** Tanpa `page`: semua guru (dipakai pilihan pengawas dan pengampu). Dengan `page`: paginasi dan pencarian `q`. */
    public function getGuru(Request $request): JsonResponse
    {
        $query = Guru::with(['user', 'mapel']);
        if (! $request->has('page')) {
            return response()->json(['data' => $query->get()]);
        }

        $q = trim((string) $request->query('q', ''));
        $query->when($q !== '', fn ($builder) => $builder->where(fn ($cari) => $cari
            ->where('nip', 'like', "%{$q}%")
            ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%"))))
            ->orderBy('id');

        return $this->halaman($query, $request);
    }

    public function storeGuru(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'nip' => 'nullable|string|max:50|unique:guru,nip',
            'mapel_ids' => 'nullable|array',
            'mapel_ids.*' => 'exists:mapel,id',
            'role' => 'nullable|string|in:guru,operator',
        ]);

        $guru = DB::transaction(function () use ($validated, $request): Guru {
            $targetRole = 'guru';
            if ($request->user() && $request->user()->role === 'superadmin' && ! empty($validated['role'])) {
                $targetRole = $validated['role'];
            }

            $user = User::create([
                'username' => $validated['username'],
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'role' => $targetRole,
                'aktif' => true,
            ]);

            $guru = Guru::create([
                'user_id' => $user->id,
                'nip' => $validated['nip'] ?? null,
            ]);

            if (! empty($validated['mapel_ids'])) {
                foreach ($validated['mapel_ids'] as $mId) {
                    GuruMapel::create([
                        'guru_id' => $guru->id,
                        'mapel_id' => (int) $mId,
                    ]);
                }
            }

            return $guru;
        });

        return response()->json(['data' => $guru->load(['user', 'mapel']), 'message' => 'Guru berhasil ditambahkan.'], 201);
    }

    public function updateGuru(Request $request, int $id): JsonResponse
    {
        $guru = Guru::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|max:100|unique:users,username,'.$guru->user_id,
            'password' => 'nullable|string|min:6',
            'nip' => 'nullable|string|max:50|unique:guru,nip,'.$guru->id,
            'mapel_ids' => 'nullable|array',
            'mapel_ids.*' => 'exists:mapel,id',
            'role' => 'nullable|string|in:guru,operator',
        ]);

        DB::transaction(function () use ($guru, $validated, $request): void {
            if (isset($validated['name'])) {
                $guru->user->name = $validated['name'];
            }
            if (isset($validated['username'])) {
                $guru->user->username = $validated['username'];
            }
            if (! empty($validated['password'])) {
                $guru->user->password = Hash::make($validated['password']);
            }
            if ($request->user() && $request->user()->role === 'superadmin' && isset($validated['role'])) {
                $guru->user->role = $validated['role'];
            }
            $guru->user->save();

            $guru->update(['nip' => $validated['nip'] ?? $guru->nip]);

            if (array_key_exists('mapel_ids', $validated)) {
                // Mapel yang tidak lagi dipilih dilepas dari guru, termasuk pengampuan per kelas.
                $dipilih = array_map('intval', $validated['mapel_ids'] ?? []);
                GuruMapel::where('guru_id', $guru->id)->whereNotIn('mapel_id', $dipilih)->delete();
                foreach ($dipilih as $mId) {
                    if (! GuruMapel::where('guru_id', $guru->id)->where('mapel_id', $mId)->exists()) {
                        GuruMapel::create(['guru_id' => $guru->id, 'mapel_id' => $mId]);
                    }
                }
            }
        });

        return response()->json(['data' => $guru->fresh()->load(['user', 'mapel']), 'message' => 'Guru berhasil diperbarui.']);
    }

    public function destroyGuru(int $id): JsonResponse
    {
        $guru = Guru::findOrFail($id);
        $user = $guru->user;
        DB::transaction(function () use ($guru, $user): void {
            $guru->delete();
            if ($user) {
                $this->nonaktifkanAkun($user);
            }
        });

        return response()->json(['message' => 'Guru berhasil dinonaktifkan.']);
    }

    // ==========================================
    // MAPEL
    // ==========================================
    /**
     * Guru hanya melihat mapel yang diampunya; Operator/Superadmin melihat semua mapel.
     */
    public function getMapel(Request $request): JsonResponse
    {
        $query = Mapel::orderBy('nama');
        $user = $request->user();
        if ($user->role === 'guru') {
            $query->whereIn('id', GuruMapel::where('guru_id', $user->guru?->id ?? 0)->select('mapel_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storeMapel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:30|unique:mapel,kode',
            'nama' => 'required|string|max:100',
            'tingkat' => 'nullable|in:7,8,9',
        ]);

        $mapel = Mapel::create($validated);

        return response()->json(['data' => $mapel, 'message' => 'Mata pelajaran berhasil ditambahkan.'], 201);
    }

    public function updateMapel(Request $request, int $id): JsonResponse
    {
        $mapel = Mapel::findOrFail($id);
        $validated = $request->validate([
            'kode' => 'sometimes|required|string|max:30|unique:mapel,kode,'.$mapel->id,
            'nama' => 'sometimes|required|string|max:100',
            'tingkat' => 'nullable|in:7,8,9',
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
    /** Tanpa `page`: semua pengampu. Dengan `page`: paginasi dan pencarian `q` (guru, kode/nama mapel, kelas). */
    public function getGuruMapel(Request $request): JsonResponse
    {
        $query = GuruMapel::with(['guru.user', 'mapel', 'kelas', 'tahunAjaran']);
        if (! $request->has('page')) {
            return response()->json(['data' => $query->get()]);
        }

        $q = trim((string) $request->query('q', ''));
        $query->when($q !== '', fn ($builder) => $builder->where(fn ($cari) => $cari
            ->whereHas('guru.user', fn ($user) => $user->where('name', 'like', "%{$q}%"))
            ->orWhereHas('mapel', fn ($mapel) => $mapel->where('kode', 'like', "%{$q}%")->orWhere('nama', 'like', "%{$q}%"))
            ->orWhereHas('kelas', fn ($kelas) => $kelas->where('nama', 'like', "%{$q}%"))))
            ->orderBy('id');

        return $this->halaman($query, $request);
    }

    public function storeGuruMapel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guru_id' => 'required|exists:guru,id',
            'mapel_id' => 'required|exists:mapel,id',
            'kelas_id' => 'required|exists:kelas,id',
            'tahun_ajaran_id' => 'required|exists:tahun_ajaran,id',
        ]);

        if ($pesan = $this->pesanTingkatTidakCocok((int) $validated['mapel_id'], (int) $validated['kelas_id'])) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => $pesan]], 422);
        }

        $gm = GuruMapel::updateOrCreate($validated, $validated);

        return response()->json([
            'data' => $gm->load(['guru.user', 'mapel', 'kelas', 'tahunAjaran']),
            'message' => 'Pengampu mapel berhasil diatur.',
        ], 201);
    }

    /** Mapel yang khusus satu tingkat hanya boleh diampu pada kelas dengan tingkat yang sama. */
    private function pesanTingkatTidakCocok(int $mapelId, int $kelasId): ?string
    {
        $mapel = Mapel::find($mapelId);
        $kelas = Kelas::find($kelasId);
        if ($mapel?->tingkat && $kelas && (string) $kelas->tingkat !== (string) $mapel->tingkat) {
            return "{$mapel->nama} khusus kelas {$mapel->tingkat}, sedangkan kelas {$kelas->nama} berada di tingkat {$kelas->tingkat}.";
        }

        return null;
    }

    public function updateGuruMapel(Request $request, int $id): JsonResponse
    {
        $gm = GuruMapel::findOrFail($id);
        $validated = $request->validate([
            'guru_id' => 'required|exists:guru,id',
            'mapel_id' => 'required|exists:mapel,id',
            'kelas_id' => 'required|exists:kelas,id',
            'tahun_ajaran_id' => 'required|exists:tahun_ajaran,id',
        ]);

        if ($pesan = $this->pesanTingkatTidakCocok((int) $validated['mapel_id'], (int) $validated['kelas_id'])) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => $pesan]], 422);
        }

        $kembar = GuruMapel::where($validated)->where('id', '!=', $gm->id)->exists();
        if ($kembar) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Pengampu mapel dengan guru, mapel, kelas, dan tahun ajaran yang sama sudah ada.']], 422);
        }

        $gm->update($validated);

        return response()->json([
            'data' => $gm->load(['guru.user', 'mapel', 'kelas', 'tahunAjaran']),
            'message' => 'Pengampu mapel berhasil diperbarui.',
        ]);
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

    private function nonaktifkanAkun(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->update(['aktif' => false]);
            $user->tokens()->delete();
            $user->deviceSessions()->where('aktif', true)->update(['aktif' => false]);
        });
    }
}
