<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\GuruMapel;
use App\Models\Pengaturan;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->username;
        $user = User::query()->where(function ($query) use ($identifier): void {
            $query->where('username', $identifier)
                ->orWhere('email', $identifier)
                ->orWhereHas('siswa', fn ($siswaQuery) => $siswaQuery->where('nis', $identifier))
                ->orWhereHas('guru', fn ($guruQuery) => $guruQuery->where('nip', $identifier));
        })
            ->first();

        if (! $user || ! $user->aktif || ! Hash::check($request->password, $user->password)) {
            return $this->error('UNAUTHENTICATED', 'Username atau password salah.', 401);
        }

        // Kunci baris user supaya dua login bersamaan tidak sama-sama lolos.
        return DB::transaction(function () use ($user, $request) {
            User::whereKey($user->id)->lockForUpdate()->first();

            // Single active session HANYA berlaku untuk role siswa (Superadmin, Operator, Guru bebas multi-perangkat)
            if ($user->role === 'siswa' && $user->tokens()->exists()) {
                $loginPolicy = Pengaturan::query()->where('key', 'login_policy')->value('value') ?? config('cbt.login_policy');
                if ($loginPolicy !== 'kick') {
                    return $this->error('ACCOUNT_IN_USE', 'Akun sedang aktif di perangkat lain, hubungi operator/pengawas.', 409);
                }
                $user->tokens()->delete(); // Jika 'kick', sesi lama dicabut
            }

            $token = $user->createToken('cbt')->plainTextToken;
            $this->auditLog->record($user, 'auth.login', 'user', $user->id, [], $request);

            return response()->json([
                'token' => $token,
                'user' => $this->userData($user),
            ]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $this->auditLog->record($request->user(), 'auth.logout', 'user', $request->user()->id, [], $request);
            $request->user()->currentAccessToken()->delete();
        });

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    /**
     * Ubah data diri, username, dan password milik sendiri (Guru, Operator, Superadmin; bukan Siswa).
     * Password saat ini wajib bila username atau password diganti.
     */
    public function updateProfil(Request $request): JsonResponse
    {
        $user = $request->user();
        $guru = $user->guru;

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'nip' => ['nullable', 'string', 'max:50', Rule::unique('guru', 'nip')->ignore($guru?->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'password_saat_ini' => ['nullable', 'string', 'max:255'],
        ], [
            'nama.required' => 'Nama wajib diisi.',
            'username.required' => 'Username wajib diisi.',
            'username.min' => 'Username minimal 3 karakter.',
            'username.regex' => 'Username hanya boleh huruf, angka, titik, garis bawah, dan strip.',
            'username.unique' => 'Username sudah dipakai pengguna lain.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah dipakai pengguna lain.',
            'nip.unique' => 'NIP sudah dipakai guru lain.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak sama.',
        ]);

        $gantiUsername = $data['username'] !== $user->username;
        $gantiPassword = ! empty($data['password']);
        if (($gantiUsername || $gantiPassword) && ! Hash::check((string) ($data['password_saat_ini'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['password_saat_ini' => 'Password saat ini salah atau belum diisi.']);
        }

        $berubah = [];
        DB::transaction(function () use ($user, $guru, $data, $gantiPassword, $request, &$berubah): void {
            $user->name = $data['nama'];
            $user->username = $data['username'];
            $user->email = ($data['email'] ?? '') !== '' ? $data['email'] : null;
            if ($gantiPassword) {
                $user->password = $data['password'];
            }
            $berubah = array_keys($user->getDirty());
            $user->save();

            if ($guru && array_key_exists('nip', $data)) {
                $guru->nip = ($data['nip'] ?? '') !== '' ? $data['nip'] : null;
                if ($guru->isDirty('nip')) {
                    $berubah[] = 'nip';
                }
                $guru->save();
            }

            if ($gantiPassword) {
                // Sesi di perangkat lain dicabut; sesi saat ini tetap aktif.
                $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
            }

            $this->auditLog->record($user, 'profil.diperbarui', 'user', $user->id, ['kolom' => array_values(array_unique($berubah))], $request);
        });

        return response()->json(['message' => 'Profil berhasil diperbarui.', 'user' => $this->userData($user->fresh())]);
    }

    private function userData(User $user): array
    {
        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'nama' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];

        if ($user->guru) {
            $data['nip'] = $user->guru->nip;
            $data['guru_id'] = $user->guru->id;
            $data['mapel_ids'] = GuruMapel::where('guru_id', $user->guru->id)->pluck('mapel_id')->unique()->values()->all();
        }

        return $data;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
