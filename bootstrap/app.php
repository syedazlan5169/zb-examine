<?php

use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Stable {message, code} contract for the photo-upload API (Step 3B.2).
        $exceptions->render(fn (PhotoUploadSessionInvalid $e) => response()->json([
            'message' => __('photo_upload.errors.'.$e->getErrorCode()),
            'code' => $e->getErrorCode(),
        ], $e->httpStatus()));

        $exceptions->render(fn (PhotoUploadInvalid $e) => response()->json([
            'message' => __('photo_upload.errors.'.$e->getErrorCode()),
            'code' => $e->getErrorCode(),
        ], $e->httpStatus()));
    })->create();
