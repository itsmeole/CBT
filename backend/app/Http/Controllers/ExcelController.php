<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\ExcelService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        try {
            $count = $this->excelService->commit($jenis, $request->input('items'));

            return response()->json([
                'message' => "Berhasil mengimpor {$count} data {$jenis}.",
                'imported_count' => $count,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => ['code' => 'IMPORT_COMMIT_FAILED', 'message' => $e->getMessage()]], 400);
        }
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
