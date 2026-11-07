<?php

namespace App\Http\Controllers;

use App\Models\BankSoal;
use App\Models\GuruMapel;
use App\Models\Media;
use App\Models\OpsiSoal;
use App\Services\HtmlSanitizerService;
use App\Services\OtorisasiCbtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BankSoalController extends Controller
{
    public function __construct(private readonly OtorisasiCbtService $otorisasi) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = BankSoal::query()->with(['mapel', 'guru.user', 'opsiSoal']);

        if ($user->role === 'guru') {
            // Isolasi guru mapel: hanya soal milik sendiri pada mapel yang diampu.
            $mapelIds = GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id')->unique();
            $query->where('guru_id', $user->guru?->id ?? 0)->whereIn('mapel_id', $mapelIds);
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
        $query = BankSoal::query()->with(['mapel', 'guru.user', 'opsiSoal']);
        if ($user->role === 'guru') {
            $query->where('guru_id', $user->guru?->id ?? 0)
                ->whereIn('mapel_id', GuruMapel::where('guru_id', $user->guru?->id)->pluck('mapel_id'));
        }
        $soal = $query->whereKey($id)->firstOrFail();

        return response()->json(['data' => $soal]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'tipe' => 'required|in:pg,pg_kompleks,benar_salah,menjodohkan,essay',
            'konten' => 'required|string',
            'kunci' => 'required_if:tipe,pg,pg_kompleks,benar_salah,menjodohkan,essay',
            'rubrik' => 'nullable|string',
            'opsi' => 'required_if:tipe,pg,pg_kompleks,benar_salah,menjodohkan|array',
            'opsi.*.konten' => 'required|string|max:20000',
            'opsi.*.sisi' => 'required_if:tipe,menjodohkan|string|in:kiri,kanan',
        ]);

        $validationErrors = $this->validasiStrukturSoal($validated);
        if ($validationErrors !== []) {
            return response()->json(['error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Struktur soal tidak valid.',
                'details' => $validationErrors,
            ]], 422);
        }

        if ($user->role === 'guru') {
            if (! $this->otorisasi->bolehKelolaMapel($user, (int) $validated['mapel_id'])) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak diizinkan membuat soal untuk mapel ini.']], 403);
            }
            $guruId = $user->guru->id;
        } else {
            $request->validate(['guru_id' => 'required|exists:guru,id']);
            $guruId = (int) $request->input('guru_id');
        }

        return DB::transaction(function () use ($validated, $guruId) {
            $soal = BankSoal::create([
                'guru_id' => $guruId,
                'mapel_id' => $validated['mapel_id'],
                'tipe' => $validated['tipe'],
                'konten' => HtmlSanitizerService::sanitize($validated['konten']),
                'kunci' => $this->normalisasiKunci($validated['tipe'], $validated['kunci']),
                'rubrik' => $validated['rubrik'] ?? null,
                'versi' => 1,
            ]);

            if (! empty($validated['opsi'])) {
                $urutanPerSisi = [];
                foreach ($validated['opsi'] as $idx => $opsiData) {
                    $sisi = $opsiData['sisi'] ?? null;
                    $urutan = $sisi
                        ? ($urutanPerSisi[$sisi] = ($urutanPerSisi[$sisi] ?? 0) + 1)
                        : $idx + 1;
                    OpsiSoal::create([
                        'bank_soal_id' => $soal->id,
                        'konten' => HtmlSanitizerService::sanitize($opsiData['konten']),
                        'sisi' => $sisi,
                        'urutan' => $urutan,
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
            if ((int) $soal->guru_id !== (int) $user->guru?->id
                || ! $this->otorisasi->bolehKelolaMapel($user, (int) $soal->mapel_id)) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke soal mapel ini.']], 403);
            }
        }

        if ($this->digunakanDiJadwal($soal->id)) {
            return response()->json(['error' => ['code' => 'IN_USE', 'message' => 'Soal sudah digunakan pada jadwal ujian dan tidak dapat diubah. Buat soal baru sebagai versi berikutnya.']], 422);
        }

        $validated = $request->validate([
            'konten' => 'sometimes|required|string',
            'kunci' => 'sometimes|required',
            'rubrik' => 'nullable|string',
            'opsi' => 'nullable|array',
            'opsi.*.konten' => 'required|string|max:20000',
            'opsi.*.sisi' => 'nullable|string|in:kiri,kanan',
        ]);

        $validatedForStructure = [
            'tipe' => $soal->tipe,
            'kunci' => $validated['kunci'] ?? $soal->kunci,
            'rubrik' => $validated['rubrik'] ?? $soal->rubrik,
            'opsi' => $validated['opsi'] ?? $soal->opsiSoal->map(fn ($opsi): array => [
                'konten' => $opsi->konten,
                'sisi' => $opsi->sisi,
                'urutan' => $opsi->urutan,
            ])->all(),
        ];
        $validationErrors = $this->validasiStrukturSoal($validatedForStructure);
        if ($validationErrors !== []) {
            return response()->json(['error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Struktur soal tidak valid.',
                'details' => $validationErrors,
            ]], 422);
        }

        return DB::transaction(function () use ($soal, $validated) {
            if (isset($validated['konten'])) {
                $validated['konten'] = HtmlSanitizerService::sanitize($validated['konten']);
            }
            if (array_key_exists('kunci', $validated)) {
                $validated['kunci'] = $this->normalisasiKunci($soal->tipe, $validated['kunci']);
            }
            $soal->update($validated);

            if (isset($validated['opsi'])) {
                $soal->opsiSoal()->delete();
                $urutanPerSisi = [];
                foreach ($validated['opsi'] as $idx => $opsiData) {
                    $sisi = $opsiData['sisi'] ?? null;
                    $urutan = $sisi
                        ? ($urutanPerSisi[$sisi] = ($urutanPerSisi[$sisi] ?? 0) + 1)
                        : $idx + 1;
                    OpsiSoal::create([
                        'bank_soal_id' => $soal->id,
                        'konten' => HtmlSanitizerService::sanitize($opsiData['konten']),
                        'sisi' => $sisi,
                        'urutan' => $urutan,
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
            if ((int) $soal->guru_id !== (int) $user->guru?->id
                || ! $this->otorisasi->bolehKelolaMapel($user, (int) $soal->mapel_id)) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke soal mapel ini.']], 403);
            }
        }

        if ($this->digunakanDiJadwal($soal->id)) {
            return response()->json(['error' => ['code' => 'IN_USE', 'message' => 'Soal sudah digunakan pada jadwal ujian dan tidak dapat dihapus.']], 422);
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

        $validImageMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $validAudioMimes = ['audio/mpeg', 'audio/mp3', 'audio/x-m4a', 'audio/mp4'];

        if (! in_array($mime, array_merge($validImageMimes, $validAudioMimes))) {
            return response()->json(['error' => ['code' => 'INVALID_MIME', 'message' => 'File harus berupa gambar (jpg, png, webp) atau audio (mp3, m4a).']], 422);
        }

        if (in_array($mime, $validImageMimes, true)) {
            if ($file->getSize() > 2 * 1024 * 1024) {
                return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Ukuran gambar maksimal 2 MB.']], 422);
            }

            $path = $this->simpanGambarTeroptimasi($file, $mime);
            if ($path instanceof JsonResponse) {
                return $path;
            }
        } else {
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, ['mp3', 'm4a'], true)) {
                return response()->json(['error' => ['code' => 'INVALID_MIME', 'message' => 'Audio harus berformat MP3 atau M4A.']], 422);
            }

            $path = $file->store('media_soal', 'public');
            if ($path === false) {
                return response()->json(['error' => ['code' => 'MEDIA_STORAGE_FAILED', 'message' => 'Audio gagal disimpan.']], 500);
            }
        }

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

    private function simpanGambarTeroptimasi(UploadedFile $file, string $mime): string|JsonResponse
    {
        if (! function_exists('imagecreatefromstring')) {
            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_UNAVAILABLE', 'message' => 'Pemrosesan gambar belum tersedia di server.']], 503);
        }
        $encoder = match ($mime) {
            'image/jpeg' => 'imagejpeg',
            'image/png' => 'imagepng',
            'image/webp' => 'imagewebp',
        };
        if (! function_exists($encoder)) {
            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_UNAVAILABLE', 'message' => 'Format gambar belum didukung oleh server.']], 503);
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            return response()->json(['error' => ['code' => 'INVALID_MIME', 'message' => 'Isi file bukan gambar yang valid.']], 422);
        }

        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1 || $width * $height > 30_000_000) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Dimensi gambar tidak didukung.']], 422);
        }

        $imageData = file_get_contents($file->getRealPath());
        if ($imageData === false) {
            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_FAILED', 'message' => 'Gambar gagal dibaca.']], 422);
        }

        $source = imagecreatefromstring($imageData);
        if ($source === false) {
            return response()->json(['error' => ['code' => 'INVALID_MIME', 'message' => 'Gambar tidak dapat diproses.']], 422);
        }

        $targetWidth = min($width, 1280);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);

            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_FAILED', 'message' => 'Memori tidak cukup untuk memproses gambar.']], 422);
        }

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cbt_image_');
        if ($temporaryPath === false) {
            imagedestroy($source);
            imagedestroy($target);

            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_FAILED', 'message' => 'Penyimpanan sementara tidak tersedia.']], 500);
        }

        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($target, $temporaryPath, 82),
            'image/png' => imagepng($target, $temporaryPath, 6),
            'image/webp' => imagewebp($target, $temporaryPath, 82),
        };
        imagedestroy($source);
        imagedestroy($target);

        if (! $saved) {
            unlink($temporaryPath);

            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_FAILED', 'message' => 'Gambar gagal dikompres.']], 422);
        }

        $path = 'media_soal/'.Str::uuid().'.'.$extension;
        $optimizedImage = file_get_contents($temporaryPath);
        unlink($temporaryPath);
        if ($optimizedImage === false) {
            return response()->json(['error' => ['code' => 'MEDIA_PROCESSING_FAILED', 'message' => 'Gambar hasil kompresi gagal dibaca.']], 500);
        }

        $stored = Storage::disk('public')->put($path, $optimizedImage);
        if (! $stored) {
            return response()->json(['error' => ['code' => 'MEDIA_STORAGE_FAILED', 'message' => 'Gambar gagal disimpan.']], 500);
        }

        return $path;
    }

    private function digunakanDiJadwal(int $soalId): bool
    {
        return DB::table('paket_soal_item')
            ->join('jadwal_ujian', 'jadwal_ujian.paket_soal_id', '=', 'paket_soal_item.paket_soal_id')
            ->where('paket_soal_item.bank_soal_id', $soalId)
            ->exists();
    }

    private function validasiStrukturSoal(array $soal): array
    {
        $tipe = $soal['tipe'];
        $opsi = $soal['opsi'] ?? [];
        $kunci = $soal['kunci'] ?? null;
        $errors = [];

        if (in_array($tipe, ['pg', 'pg_kompleks'], true)) {
            $jumlah = count($opsi);
            $maks = $tipe === 'pg' ? 5 : 6;
            if ($jumlah < 2 || $jumlah > $maks) {
                $errors['opsi'] = ["Tipe {$tipe} harus memiliki 2 sampai {$maks} opsi."];
            }

            $labels = $jumlah > 0 ? range('A', chr(64 + $jumlah)) : [];
            $rawKeys = is_array($kunci)
                ? (array_is_list($kunci) ? array_map('strval', $kunci) : array_map('strval', array_keys($kunci)))
                : [(string) $kunci];

            $keys = array_map(function ($k) {
                $str = strtoupper(trim((string) $k));
                if (ctype_digit($str) && (int) $str >= 1) {
                    return chr(64 + (int) $str);
                }

                return $str;
            }, $rawKeys);

            if ($tipe === 'pg' && (count($keys) !== 1 || ! in_array($keys[0], $labels, true))) {
                $errors['kunci'] = ['Kunci PG harus menunjuk tepat satu opsi yang tersedia.'];
            }
            if ($tipe === 'pg_kompleks' && (count($keys) < 2 || count(array_unique($keys)) !== count($keys) || array_diff($keys, $labels) !== [])) {
                $errors['kunci'] = ['Kunci PG kompleks harus berisi minimal dua opsi yang tersedia.'];
            }
        } elseif ($tipe === 'benar_salah') {
            $jumlah = count($opsi);
            $kunciMap = is_array($kunci) ? $kunci : [];
            $expectedKeys = $jumlah > 0 ? array_map('strval', range(1, $jumlah)) : [];
            $actualKeys = array_map('strval', array_keys($kunciMap));
            sort($expectedKeys);
            sort($actualKeys);
            if ($jumlah < 1 || $actualKeys !== $expectedKeys
                || array_diff(array_map(fn ($value) => strtoupper((string) $value), array_values($kunciMap)), ['B', 'S']) !== []) {
                $errors['kunci'] = ['Setiap pernyataan harus memiliki kunci B atau S.'];
            }
        } elseif ($tipe === 'menjodohkan') {
            $kiri = array_values(array_filter($opsi, fn ($item) => ($item['sisi'] ?? null) === 'kiri'));
            $kanan = array_values(array_filter($opsi, fn ($item) => ($item['sisi'] ?? null) === 'kanan'));
            $kunciMap = is_array($kunci) ? $kunci : [];
            $expectedLeftKeys = $kiri === [] ? [] : array_map('strval', range(1, count($kiri)));
            $actualLeftKeys = array_map('strval', array_keys($kunciMap));
            sort($expectedLeftKeys);
            sort($actualLeftKeys);
            if ($kiri === [] || count($kanan) < count($kiri) || count($kunciMap) !== count($kiri)
                || count(array_unique(array_column($kiri, 'konten'))) !== count($kiri)
                || count(array_unique(array_column($kanan, 'konten'))) !== count($kanan)
                || $actualLeftKeys !== $expectedLeftKeys) {
                $errors['opsi'] = ['Soal menjodohkan harus memiliki respons yang cukup dan satu kunci per premis.'];
            }
            $labelKanan = $kanan === [] ? [] : range('a', chr(96 + count($kanan)));
            $normalizedAnswers = array_map(function ($val) {
                $valStr = strtolower(trim((string) $val));
                if (ctype_digit($valStr) && (int) $valStr >= 1) {
                    return chr(96 + (int) $valStr);
                }

                return $valStr;
            }, array_values($kunciMap));

            if ($kunciMap !== [] && (array_diff($normalizedAnswers, $labelKanan) !== []
                || count(array_unique($normalizedAnswers)) !== count($normalizedAnswers))) {
                $errors['kunci'] = ['Kunci menjodohkan harus mengarah ke pilihan yang tersedia.'];
            }
        } elseif ($tipe === 'essay' && (trim((string) $kunci) === '' || trim((string) ($soal['rubrik'] ?? '')) === '')) {
            $errors['kunci'] = ['Soal essay wajib memiliki kunci jawaban dan rubrik.'];
        }

        foreach ($opsi as $index => $item) {
            if (trim((string) ($item['konten'] ?? '')) === '') {
                $errors["opsi.{$index}.konten"] = ['Konten opsi wajib diisi.'];
            }
        }

        return $errors;
    }

    private function normalisasiKunci(string $tipe, mixed $kunci): mixed
    {
        if ($tipe === 'pg') {
            $val = is_array($kunci) ? (string) reset($kunci) : strtoupper((string) $kunci);
            if (ctype_digit($val) && (int) $val >= 1) {
                return chr(64 + (int) $val);
            }

            return $val;
        }
        if ($tipe === 'pg_kompleks') {
            $values = (array) $kunci;
            $items = array_is_list($values) ? $values : array_keys($values);

            return array_values(array_map(function ($value) {
                $v = strtoupper(trim((string) $value));
                if (ctype_digit($v) && (int) $v >= 1) {
                    return chr(64 + (int) $v);
                }

                return $v;
            }, $items));
        }
        if ($tipe === 'benar_salah') {
            return array_map(fn ($value) => strtoupper((string) $value), (array) $kunci);
        }
        if ($tipe === 'menjodohkan' && is_array($kunci)) {
            $normalized = [];
            foreach ($kunci as $k => $v) {
                $valStr = strtolower(trim((string) $v));
                if (ctype_digit($valStr) && (int) $valStr >= 1) {
                    $valStr = chr(96 + (int) $valStr);
                }
                $normalized[(string) $k] = $valStr;
            }

            return $normalized;
        }

        return $kunci;
    }
}
