<?php

namespace App\Http\Controllers;

use App\Models\LogAktivitas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdministrasiUjianController extends Controller
{
    public function logAktivitas(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'aksi' => ['nullable', 'string', 'max:100'],
            'target_type' => ['nullable', 'string', 'max:100'],
            'mulai' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:mulai'],
            'cari' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = LogAktivitas::query()
            ->with('aktor:id,name,username,role')
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['aksi']), fn ($query) => $query->where('aksi', $filters['aksi']))
            ->when(isset($filters['target_type']), fn ($query) => $query->where('target_type', $filters['target_type']))
            ->when(isset($filters['cari']), fn ($query) => $query->whereHas('aktor', fn ($aktor) => $aktor
                ->where('name', 'like', '%'.$filters['cari'].'%')
                ->orWhere('username', 'like', '%'.$filters['cari'].'%')))
            ->when(isset($filters['mulai']), fn ($query) => $query->where('created_at', '>=', $filters['mulai']))
            ->when(isset($filters['sampai']), fn ($query) => $query->where('created_at', '<=', $filters['sampai']))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json($rows);
    }
}
