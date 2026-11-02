<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
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
        $exceptions->render(fn (ValidationException $e, Request $r) => $r->is('api/*')
            ? $error('VALIDATION_ERROR', 'Data tidak valid.', 422, ['fields' => $e->errors()]) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $r->is('api/*')
            ? $error('RATE_LIMITED', 'Terlalu banyak percobaan, coba lagi sebentar.', 429) : null);
    })->create();
