<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            // Single active session HANYA berlaku untuk role siswa (Superadmin, Operator, Guru bebas multi-perangkat)
            if ($user->role === 'siswa' && $user->tokens()->exists()) {
                $loginPolicy = Pengaturan::query()->where('key', 'login_policy')->value('value') ?? config('cbt.login_policy');
                if ($loginPolicy !== 'kick') {
                    return $this->error('ACCOUNT_IN_USE', 'Akun sedang aktif di perangkat lain, hubungi operator/pengawas.', 409);
                }
                $user->tokens()->delete(); // Jika 'kick', sesi lama dicabut
            }

            return response()->json([
                'token' => $user->createToken('cbt')->plainTextToken,
                'user' => $this->userData($user),
            ]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'nama' => $user->name,
            'role' => $user->role,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
