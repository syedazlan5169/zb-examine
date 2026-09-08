<?php

use App\Http\Controllers\ExaminationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExaminationController::class, 'create'])->name('examinations.create');
Route::post('/examinations', [ExaminationController::class, 'store'])->name('examinations.store');
Route::get('/examinations/success', [ExaminationController::class, 'success'])->name('examinations.success');

Route::get('/language/{locale}', function (string $locale) {
    abort_unless(
        in_array($locale, ['ms', 'en'], true),
        404
    );

    session(['locale' => $locale]);

    return back();
})->name('language.switch');

Route::view('/locale-test', 'locale-test');
