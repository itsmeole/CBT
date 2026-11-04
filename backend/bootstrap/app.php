<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:submit-peserta-kedaluwarsa')
            ->everyThirtySeconds()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => EnsureRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(
            ['error' => ['code' => $code, 'message' => $message] + $extra], $status
        );

        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*')
            ? $error('UNAUTHENTICATED', 'Silakan login terlebih dahulu.', 401) : null);
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $r) => $r->is('api/*')
            ? $error('FORBIDDEN', 'Anda tidak memiliki akses.', 403) : null);
        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $r->is('api/*')
            ? $error('NOT_FOUND', 'Data atau endpoint tidak ditemukan.', 404) : null);
        $exceptions->render(fn (ValidationException $e, Request $r) => $r->is('api/*')
            ? $error('VALIDATION_ERROR', 'Data tidak valid.', 422, ['fields' => $e->errors(), 'details' => $e->errors()]) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $r->is('api/*')
            ? $error('RATE_LIMITED', 'Terlalu banyak percobaan, coba lagi sebentar.', 429) : null);
    })->create();
