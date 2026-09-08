<?php

use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\PhotoUploadController;
use App\Http\Controllers\PhotoUploadSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExaminationController::class, 'create'])->name('examinations.create');
Route::post('/examinations', [ExaminationController::class, 'store'])->name('examinations.store');
Route::get('/examinations/success', [ExaminationController::class, 'success'])->name('examinations.success');

// Standard `web` group (CSRF included, unchanged) \u2014 bearer-token session auth
// and CSRF are separate, complementary protections (see docs/DECISIONS.md).
Route::post('/photo-upload-sessions', [PhotoUploadSessionController::class, 'store'])
    ->name('photo-upload-sessions.store');
Route::get('/photo-upload-sessions/{sessionPublicId}', [PhotoUploadSessionController::class, 'show'])
    ->name('photo-upload-sessions.show');
Route::post('/photo-upload-sessions/{sessionPublicId}/photos', [PhotoUploadController::class, 'store'])
    ->name('photo-upload-sessions.photos.store');
Route::post('/photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/upload', [PhotoUploadController::class, 'upload'])
    ->name('photo-upload-sessions.photos.upload');
Route::post('/photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/complete', [PhotoUploadController::class, 'complete'])
    ->name('photo-upload-sessions.photos.complete');
Route::delete('/photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}', [PhotoUploadController::class, 'destroy'])
    ->name('photo-upload-sessions.photos.destroy');

Route::get('/language/{locale}', function (string $locale) {
    abort_unless(
        in_array($locale, ['ms', 'en'], true),
        404
    );

    session(['locale' => $locale]);

    return back();
})->name('language.switch');

Route::view('/locale-test', 'locale-test');
