<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->username)->first();

        if (! $user || ! $user->aktif || ! Hash::check($request->password, $user->password)) {
            return $this->error('UNAUTHENTICATED', 'Username atau password salah.', 401);
        }

        // Kunci baris user supaya dua login bersamaan tidak sama-sama lolos.
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->tokens()->exists()) {
                if (config('cbt.login_policy') !== 'kick') {
                    return $this->error('ACCOUNT_IN_USE', 'Akun sedang aktif di perangkat lain, hubungi operator/pengawas.', 409);
                }
                $user->tokens()->delete(); // ponytail: event ForceLogout menyusul bersama Reverb (Fase 2)
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

