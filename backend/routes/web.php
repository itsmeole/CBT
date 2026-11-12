<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/storage/{path}', function (string $path) {
    $cleanPath = str_replace(['..', "\0"], '', $path);
    $cleanPath = ltrim($cleanPath, '/\\');

    $candidates = [
        public_path('storage/'.$cleanPath),
        storage_path('app/public/'.$cleanPath),
        storage_path('app/'.$cleanPath),
    ];

    foreach ($candidates as $file) {
        if (file_exists($file) && ! is_dir($file)) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $mimes = [
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'svg' => 'image/svg+xml',
                    'mp3' => 'audio/mpeg',
                    'm4a' => 'audio/mp4',
                    'wav' => 'audio/wav',
                ];
                $mime = $mimes[$ext] ?? 'application/octet-stream';

                return response($data, 200, [
                    'Content-Type' => $mime,
                    'Content-Length' => strlen($data),
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }
    }

    abort(404, 'Berkas tidak ditemukan.');
})->withoutMiddleware([
    PreventRequestForgery::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->where('path', '.*');

/*
 * SEMENTARA: jalankan perintah artisan lewat browser karena hosting tidak punya terminal. HAPUS setelah deploy.
 * Aktif hanya jika CBT_ARTISAN_TOKEN (min. 16 karakter) diisi di .env. Contoh:
 *   /_artisan/migrate?token=XXX
 *   /_artisan/seed?token=XXX&class=DataSekolahTestingSeeder   (tanpa class = DatabaseSeeder)
 * Grup 'web' dilepas agar tetap bisa dipanggil walau APP_KEY belum ada (untuk key-generate).
 */
Route::get('/_artisan/{cmd}', function (string $cmd) {
    $token = (string) config('cbt.artisan_token');
    abort_if(strlen($token) < 16 || ! hash_equals($token, (string) request('token')), 404);

    $class = (string) request('class', 'DatabaseSeeder');
    abort_unless(preg_match('/^\w+$/', $class) && class_exists('Database\\Seeders\\'.$class), 422, 'Seeder tidak ditemukan.');

    [$command, $args] = match ($cmd) {
        'migrate' => ['migrate', ['--force' => true]],
        'seed' => ['db:seed', ['--class' => 'Database\\Seeders\\'.$class, '--force' => true]],
        'key-generate' => ['key:generate', ['--force' => true]],
        'view-clear' => ['view:clear', []],
        'cache-clear' => ['cache:clear', []],
        'config-clear' => ['config:clear', []],
        'storage-link' => ['storage:link', ['--force' => true]],
        default => abort(404),
    };

    try {
        $code = Artisan::call($command, $args);
        $out = "$ php artisan $command\nexit code: $code\n\n".Artisan::output();
    } catch (Throwable $e) {
        $out = "$ php artisan $command\nGAGAL: ".$e->getMessage();
    }

    return response($out, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->withoutMiddleware('web')->where('cmd', '[a-z-]+');

/*
 * Frontend (SPA) disajikan dari domain yang sama dengan API: halaman apa pun selain API dan berkas statis
 * mengembalikan app.html (hasil build frontend), lalu Vue Router yang menentukan tampilannya.
 * Tanpa build frontend (mis. saat pengembangan) rute '/' menampilkan halaman bawaan Laravel.
 */
Route::get('/{any?}', function () {
    $app = public_path('app.html');
    if (! file_exists($app)) {
        abort_unless(request()->path() === '/', 404);

        return view('welcome');
    }

    return response()->file($app, ['Cache-Control' => 'no-cache']);
})->withoutMiddleware([
    PreventRequestForgery::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->where('any', '^(?!api/|storage/|broadcasting/|sanctum/|up$).*$');
