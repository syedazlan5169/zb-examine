<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/language/{locale}', function (string $locale) {
    abort_unless(
        in_array($locale, ['ms', 'en'], true),
        404
    );

    session(['locale' => $locale]);

    return back();
})->name('language.switch');

Route::view('/locale-test', 'locale-test');
