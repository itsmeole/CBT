<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\PaketSoal;
use App\Services\AuditLogService;
use App\Services\OtorisasiCbtService;
use App\Services\WordSoalService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WordSoalController extends Controller
{
    public function __construct(
        private readonly WordSoalService $wordSoalService,
        private readonly AuditLogService $auditLog,
        private readonly OtorisasiCbtService $otorisasi,
    ) {}

    public function template(): BinaryFileResponse
    {
        $filePath = $this->wordSoalService->generateTemplate();

        return response()->download($filePath, 'template_soal.docx')->deleteFileAfterSend(true);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:docx|max:20480',
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
            $guru = $user->guru ?? \App\Models\Guru::create(['user_id' => $user->id]);
            $guruId = $guru->id;
            \App\Models\GuruMapel::firstOrCreate([
                'guru_id' => $guruId,
                'mapel_id' => (int) $validated['mapel_id'],
            ]);
        } else {
            $request->validate(['guru_id' => 'required|exists:guru,id']);
            $guruId = (int) $request->input('guru_id');
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
        $validated = $request->validate([
            'mapel_id' => ['nullable', 'integer', 'exists:mapel,id'],
        ]);
        $query = BankSoal::query();

        if (isset($validated['mapel_id'])) {
            $query->where('mapel_id', $validated['mapel_id']);
        } elseif ($user->role === 'guru') {
            $query->where('guru_id', $user->guru?->id ?? 0);
        }

        $soalList = $query->with('opsiSoal')->orderBy('id')->get();
        if ($soalList->isEmpty()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tidak ada soal yang dapat diekspor.']], 404);
        }

        $filePath = $this->wordSoalService->exportToWord($soalList);
        $this->auditLog->record(
            $user,
            'bank_soal.export_word',
            'bank_soal',
            null,
            ['jumlah_soal' => $soalList->count(), 'mapel_id' => $validated['mapel_id'] ?? null],
            $request,
        );

        return response()->download($filePath, 'bank_soal_export.docx')->deleteFileAfterSend(true);
    }

    public function exportPaket(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        $paket = PaketSoal::query()->findOrFail($id);

        if ($user->role === 'guru'
            && ((int) $paket->guru_id !== (int) $user->guru?->id
                || ! $this->otorisasi->bolehKelolaMapel($user, (int) $paket->mapel_id))) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke paket soal ini.']], 403);
        }

        $soal = $paket->items()->with('bankSoal.opsiSoal')->orderBy('urutan')->get()
            ->pluck('bankSoal')
            ->filter()
            ->values();
        if ($soal->isEmpty()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Paket soal tidak memiliki butir soal aktif.']], 404);
        }

        if ($user->role === 'guru' && $soal->contains(
            fn (BankSoal $item): bool => (int) $item->guru_id !== (int) $user->guru?->id
                || (int) $item->mapel_id !== (int) $paket->mapel_id,
        )) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Paket memuat soal yang bukan milik Anda.']], 403);
        }

        $filePath = $this->wordSoalService->exportToWord($soal);
        $this->auditLog->record(
            $user,
            'paket_soal.export_word',
            'paket_soal',
            $paket->id,
            ['jumlah_soal' => $soal->count()],
            $request,
        );

        return response()->download($filePath, 'paket_soal_'.$paket->id.'.docx')->deleteFileAfterSend(true);
    }
}
