<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
])->where('path', '.*');
