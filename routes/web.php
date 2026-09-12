<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AgentExaminationHistoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ExaminationPhotoPreviewController;
use App\Http\Controllers\PhotoUploadController;
use App\Http\Controllers\PhotoUploadSessionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffExaminationController;
use App\Http\Controllers\TemporaryPhotoPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExaminationController::class, 'root'])->name('home');
Route::get('/examinations/create', [ExaminationController::class, 'create'])->name('examinations.create');
Route::post('/examinations', [ExaminationController::class, 'store'])->name('examinations.store');
Route::get('/examinations/success', [ExaminationController::class, 'success'])->name('examinations.success');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/password', [ProfileController::class, 'password'])->name('profile.password.edit');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
});

Route::middleware(['auth', 'active'])
    ->prefix('my-examinations')
    ->name('agent.examinations.')
    ->group(function () {
        Route::get('/', [AgentExaminationHistoryController::class, 'index'])->name('index');
        Route::get('/{examination}', [AgentExaminationHistoryController::class, 'show'])->name('show');
    });

Route::get('/examinations', [StaffExaminationController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('examinations.index');
Route::get('/examinations/{examination}', [StaffExaminationController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('examinations.show');
Route::get('/examinations/{examination}/photos/{photo}/preview', [ExaminationPhotoPreviewController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->scopeBindings()
    ->name('examinations.photos.preview');

Route::middleware(['auth', 'active'])
    ->prefix('reports')
    ->name('reports.')
    ->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/export', [ReportController::class, 'export'])->name('export');
    });

Route::get('/login', [AuthController::class, 'create'])
    ->middleware('guest')
    ->name('login');
Route::post('/login', [AuthController::class, 'store'])
    ->middleware(['guest', 'throttle:login'])
    ->name('auth.login.store');
Route::post('/logout', [AuthController::class, 'destroy'])
    ->middleware(['auth', 'active'])
    ->name('auth.logout');

Route::get('/register', [RegistrationController::class, 'create'])
    ->middleware('guest')
    ->name('register');
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware('guest')
    ->name('auth.register.store');

Route::middleware(['auth', 'active', 'can:viewAny,App\\Models\\User'])
    ->prefix('admin/users')
    ->name('admin.users.')
    ->group(function (): void {
        Route::get('/', [AdminUserController::class, 'index'])->name('index');
        Route::get('/create', [AdminUserController::class, 'create'])->name('create');
        Route::post('/', [AdminUserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [AdminUserController::class, 'edit'])->name('edit');
        Route::patch('/{user}', [AdminUserController::class, 'update'])->name('update');
        Route::get('/{user}/password', [AdminUserController::class, 'password'])->name('password.edit');
        Route::patch('/{user}/password', [AdminUserController::class, 'resetPassword'])->name('password.update');
    });

if (app()->environment(['local', 'testing'])) {
    Route::view('/dev/photo-upload-workbench', 'dev.photo-upload-workbench')
        ->name('dev.photo-upload-workbench');
}

// Standard `web` group (CSRF included, unchanged) \u2014 bearer-token session auth
// and CSRF are separate, complementary protections (see docs/DECISIONS.md).
Route::post('/photo-upload-sessions', [PhotoUploadSessionController::class, 'store'])
    ->name('photo-upload-sessions.store');
Route::get('/photo-upload-sessions/{sessionPublicId}', [PhotoUploadSessionController::class, 'show'])
    ->name('photo-upload-sessions.show');
Route::get('/photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/preview', [TemporaryPhotoPreviewController::class, 'show'])
    ->name('photo-upload-sessions.photos.preview');
Route::post('/photo-upload-sessions/{sessionPublicId}/photos', [PhotoUploadController::class, 'store'])
    ->name('photo-upload-sessions.photos.store');
Route::post('/photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/authorize', [PhotoUploadController::class, 'authorize'])
    ->name('photo-upload-sessions.photos.authorize');
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
