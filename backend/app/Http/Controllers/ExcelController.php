<?php

namespace App\Http\Controllers;

use App\Jobs\ImporExcelMasterData;
use App\Services\AuditLogService;
use App\Services\ExcelService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    public function __construct(
        private readonly ExcelService $excelService,
        private readonly AuditLogService $auditLog,
    ) {}

    public function template(string $jenis): BinaryFileResponse|JsonResponse
    {
        try {
            $filePath = $this->excelService->generateTemplate($jenis);

            return response()->download($filePath, "template_{$jenis}.xlsx")->deleteFileAfterSend(true);
        } catch (Exception $e) {
            return response()->json(['error' => ['code' => 'BAD_REQUEST', 'message' => $e->getMessage()]], 400);
        }
    }

    public function preview(Request $request, string $jenis): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        try {
            $result = $this->excelService->preview($jenis, $request->file('file'));

            return response()->json([
                'data' => $result,
                'message' => 'File berhasil divalidasi.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => ['code' => 'IMPORT_ERROR', 'message' => $e->getMessage()]], 400);
        }
    }

    public function commit(Request $request, string $jenis): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
        ]);

        if (! in_array($jenis, ['siswa', 'guru', 'kelas', 'guru_mapel'], true)) {
            return response()->json(['error' => ['code' => 'BAD_REQUEST', 'message' => 'Jenis data master tidak valid.']], 400);
        }

        // Commit dijalankan di antrean (hash password ratusan siswa terlalu lama untuk satu request HTTP).
        $importId = (string) Str::uuid();
        Cache::put(ImporExcelMasterData::kunciStatus($importId), [
            'user_id' => $request->user()->id,
            'jenis' => $jenis,
            'status' => 'antre',
        ], now()->addHour());

        ImporExcelMasterData::dispatch($importId, $jenis, $request->input('items'));
        $this->auditLog->record($request->user(), 'import.excel.diajukan', 'import_excel', null, [
            'jenis' => $jenis,
            'jumlah_baris' => count($request->input('items')),
        ], $request);

        return response()->json([
            'message' => 'Impor diproses di latar belakang.',
            'data' => ['import_id' => $importId, 'status' => 'antre'],
        ], 202);
    }

    public function status(Request $request, string $importId): JsonResponse
    {
        $status = Cache::get(ImporExcelMasterData::kunciStatus($importId));

        if (! is_array($status) || ($status['user_id'] ?? null) !== $request->user()->id) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Status impor tidak ditemukan.']], 404);
        }

        unset($status['user_id']);

        return response()->json(['data' => $status]);
    }

    public function export(Request $request, string $jenis): BinaryFileResponse|JsonResponse
    {
        if (! in_array($jenis, ['siswa', 'guru', 'kelas', 'guru_mapel'], true)) {
            return response()->json([
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'Jenis data master tidak valid untuk diekspor.'],
            ], 400);
        }

        $filePath = $this->excelService->export($jenis);
        $this->auditLog->record(
            $request->user(),
            'master_data.export_excel',
            $jenis,
            null,
            [],
            $request,
        );

        return response()->download($filePath, 'export_'.$jenis.'.xlsx')->deleteFileAfterSend(true);
    }
}
