<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePengaturanRequest;
use App\Models\Pengaturan;
use Illuminate\Http\JsonResponse;

class PengaturanController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'login_policy' => Pengaturan::query()->where('key', 'login_policy')->value('value') ?? config('cbt.login_policy'),
        ]]);
    }

    public function update(UpdatePengaturanRequest $request): JsonResponse
    {
        $policy = $request->validated('login_policy');
        Pengaturan::query()->updateOrCreate(['key' => 'login_policy'], ['value' => $policy]);

        return response()->json(['data' => ['login_policy' => $policy]]);
    }
}
