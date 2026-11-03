<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\GuruMapel;
use App\Models\Media;
use App\Models\OpsiSoal;
use App\Services\HtmlSanitizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BankSoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = BankSoal::with(['mapel', 'guru.user', 'opsiSoal']);

        // Isolasi scope guru: hanya mapel yang diampunya
        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            $query->whereIn('mapel_id', $mapelIds);
        }

        if ($request->filled('mapel_id')) {
            $query->where('mapel_id', $request->input('mapel_id'));
        }
        if ($request->filled('tipe')) {
            $query->where('tipe', $request->input('tipe'));
        }
        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where('konten', 'like', "%{$s}%");
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $soal = BankSoal::with(['mapel', 'guru.user', 'opsiSoal'])->findOrFail($id);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($soal->mapel_id)) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke soal mapel ini.']], 403);
            }
        }

        return response()->json(['data' => $soal]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'tipe' => 'required|in:pg,pg_kompleks,benar_salah,menjodohkan,essay',
            'konten' => 'required|string',
            'kunci' => 'nullable',
            'rubrik' => 'nullable|string',
            'opsi' => 'nullable|array',
            'opsi.*.konten' => 'required|string',
            'opsi.*.sisi' => 'nullable|string|in:kiri,kanan',
        ]);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($validated['mapel_id'])) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak diizinkan membuat soal untuk mapel ini.']], 403);
            }
            $guruId = $user->guru->id;
        } else {
            $guruId = $request->input('guru_id', $user->guru?->id ?? 1);
        }

        return DB::transaction(function () use ($validated, $guruId) {
            $soal = BankSoal::create([
                'guru_id' => $guruId,
                'mapel_id' => $validated['mapel_id'],
                'tipe' => $validated['tipe'],
                'konten' => HtmlSanitizerService::sanitize($validated['konten']),
                'kunci' => $validated['kunci'] ?? null,
                'rubrik' => $validated['rubrik'] ?? null,
                'versi' => 1,
            ]);

            if (!empty($validated['opsi'])) {
                foreach ($validated['opsi'] as $idx => $opsiData) {
                    OpsiSoal::create([
                        'bank_soal_id' => $soal->id,
                        'konten' => HtmlSanitizerService::sanitize($opsiData['konten']),
                        'sisi' => $opsiData['sisi'] ?? null,
                        'urutan' => $idx + 1,
                    ]);
                }
            }

            return response()->json([
                'data' => $soal->load(['opsiSoal', 'mapel']),
                'message' => 'Soal berhasil disimpan.',
            ], 201);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $soal = BankSoal::findOrFail($id);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($soal->mapel_id)) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke soal mapel ini.']], 403);
            }
        }

        $validated = $request->validate([
            'konten' => 'sometimes|required|string',
            'kunci' => 'nullable',
            'rubrik' => 'nullable|string',
            'opsi' => 'nullable|array',
            'opsi.*.konten' => 'required|string',
            'opsi.*.sisi' => 'nullable|string|in:kiri,kanan',
        ]);

        return DB::transaction(function () use ($soal, $validated) {
            if (isset($validated['konten'])) {
                $validated['konten'] = HtmlSanitizerService::sanitize($validated['konten']);
            }
            $soal->update($validated);

            if (isset($validated['opsi'])) {
                $soal->opsiSoal()->delete();
                foreach ($validated['opsi'] as $idx => $opsiData) {
                    OpsiSoal::create([
                        'bank_soal_id' => $soal->id,
                        'konten' => HtmlSanitizerService::sanitize($opsiData['konten']),
                        'sisi' => $opsiData['sisi'] ?? null,
                        'urutan' => $idx + 1,
                    ]);
                }
            }

            return response()->json([
                'data' => $soal->load(['opsiSoal', 'mapel']),
                'message' => 'Soal berhasil diperbarui.',
            ]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $soal = BankSoal::findOrFail($id);

        if ($user->role === 'guru') {
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            if (!$mapelIds->contains($soal->mapel_id)) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke soal mapel ini.']], 403);
            }
        }

        $soal->delete();
        return response()->json(['message' => 'Soal berhasil dihapus.']);
    }

    /**
     * Upload media (gambar atau audio) untuk soal (FR-BE-24).
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // maks 10MB
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();

        // Validasi format
        $validImageMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $validAudioMimes = ['audio/mpeg', 'audio/mp3', 'audio/x-m4a', 'audio/mp4'];

        if (!in_array($mime, array_merge($validImageMimes, $validAudioMimes))) {
            return response()->json(['error' => ['code' => 'INVALID_MIME', 'message' => 'File harus berupa gambar (jpg, png, webp) atau audio (mp3, m4a).']], 422);
        }

        // Simpan ke storage public
        $path = $file->store('media_soal', 'public');

        $media = Media::create([
            'user_id' => $request->user()->id,
            'path' => Storage::url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'ukuran' => $file->getSize(),
        ]);

        return response()->json([
            'data' => [
                'id' => $media->id,
                'url' => asset($media->path),
                'mime' => $media->mime,
                'name' => $media->original_name,
            ],
            'message' => 'Media berhasil diunggah.',
        ], 201);
    }
}

