<?php

use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Exceptions\PhotoUploadStorageException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Services\RoleHomeResolver;
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
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
        $middleware->redirectUsersTo(function (Request $request): string {
            return route(app(RoleHomeResolver::class)->routeName($request->user()));
        });
        $middleware->trustProxies(
            at: ['REMOTE_ADDR'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Covers only the automatic ValidationException redirect path (merges with
        // Laravel's own defaults). Manual back()->withInput() calls in
        // ExaminationController are not covered by this and sanitize separately.
        $exceptions->dontFlash(['photo_upload_token']);

        // Stable {message, code} contract for the photo-upload API (Step 3B.2).
        $exceptions->render(fn (PhotoUploadSessionInvalid $e) => response()->json([
            'message' => __('photo_upload.errors.'.$e->getErrorCode()),
            'code' => $e->getErrorCode(),
        ], $e->httpStatus()));

        $exceptions->render(fn (PhotoUploadInvalid $e) => response()->json([
            'message' => __('photo_upload.errors.'.$e->getErrorCode()),
            'code' => $e->getErrorCode(),
        ], $e->httpStatus()));

        $exceptions->render(fn (PhotoUploadStorageException $e) => response()->json([
            'message' => __('photo_upload.errors.direct_upload_failed'),
            'code' => $e->getErrorCode(),
        ], $e->httpStatus()));
    })->create();
