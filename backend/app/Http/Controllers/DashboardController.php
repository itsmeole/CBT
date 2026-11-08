<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Ringkasan dashboard menurut peran: operator untuk persiapan dan hari ujian, guru untuk koreksi dan
     * pengawasan, superadmin untuk pengawasan sistem (ditambah panel operator).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sekarang = $this->dashboard->sekarang();

        $data = [
            'peran' => $user->role,
            'tanggal' => $sekarang->toDateString(),
            'zona_waktu' => $sekarang->getTimezone()->getName(),
        ];

        if (in_array($user->role, ['operator', 'superadmin'], true)) {
            $data['operator'] = $this->dashboard->operator();
        }
        if ($user->role === 'guru') {
            $data['guru'] = $this->dashboard->guru($user);
        }
        if ($user->role === 'superadmin') {
            $data['sistem'] = $this->dashboard->sistem();
        }

        return response()->json(['data' => $data]);
    }
}
