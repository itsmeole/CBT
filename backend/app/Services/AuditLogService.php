<?php

namespace App\Services;

use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $data = [],
        ?Request $request = null,
    ): void {
        LogAktivitas::query()->forceCreate([
            'user_id' => $actor?->id,
            'aksi' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent()
                ? mb_substr($request->userAgent(), 0, 1000)
                : null,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ]);
    }
}
