<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\GuruMapel;
use App\Services\WordSoalService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WordSoalController extends Controller
{
    protected WordSoalService $wordSoalService;

    public function __construct(WordSoalService $wordSoalService)
    {
        $this->wordSoalService = $wordSoalService;
    }

    public function template(): BinaryFileResponse
    {
        $filePath = $this->wordSoalService->generateTemplate();
        return response()->download($filePath, 'template_soal.docx')->deleteFileAfterSend(true);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480', // maks 20MB
        ]);

        try {
            $result = $this->wordSoalService->preview($request->file('file'));
            return response()->json([
                'data' => $result,
                'message' => 'File Word berhasil diurai.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => ['code' => 'PARSE_ERROR', 'message' => $e->getMessage()]], 400);
        }
    }

    public function commit(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'items' => 'required|array|min:1',
        ]);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($validated['mapel_id'])) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak diizinkan menambahkan soal untuk mapel ini.']], 403);
            }
            $guruId = $user->guru->id;
        } else {
            $guruId = $request->input('guru_id', $user->guru?->id ?? 1);
        }

        try {
            $count = $this->wordSoalService->commit($guruId, $validated['mapel_id'], $validated['items']);
            return response()->json([
                'message' => "Berhasil mengimpor {$count} butir soal ke bank soal.",
                'imported_count' => $count,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => ['code' => 'COMMIT_FAILED', 'message' => $e->getMessage()]], 400);
        }
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        $query = BankSoal::with('opsiSoal');

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            $query->whereIn('mapel_id', $mapelIds);
        }

        if ($request->filled('mapel_id')) {
            $query->where('mapel_id', $request->input('mapel_id'));
        }

        $soalList = $query->get();
        if ($soalList->isEmpty()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tidak ada soal yang dapat diekspor.']], 404);
        }

        $filePath = $this->wordSoalService->exportToWord($soalList);
        return response()->download($filePath, 'bank_soal_export.docx')->deleteFileAfterSend(true);
    }
}

