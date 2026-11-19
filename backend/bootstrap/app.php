<?php

use App\Http\Middleware\AuthorizeRole;
use App\Http\Middleware\LegacyLoginGate;
use App\Http\Middleware\LegacyMultipartPut;
use App\Http\Middleware\LegacyTrim;
use App\Http\Middleware\VerifyJwt;
use App\Support\LegacyError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Express lama tidak melakukan trim global atau konversi '' -> null.
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);

        $middleware->alias([
            'verify.jwt' => VerifyJwt::class,
            'role' => AuthorizeRole::class,
            'legacy.login.gate' => LegacyLoginGate::class,
            'legacy.trim' => LegacyTrim::class,
        ]);
        $middleware->api(append: [LegacyMultipartPut::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi input gagal',
                    'errors' => $e->errors(),
                ], 400);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(
                    LegacyError::payload($e),
                    method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500
                );
            }
        });
    })->create();
