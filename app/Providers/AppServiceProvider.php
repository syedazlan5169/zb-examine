<?php

namespace App\Providers;

use App\Services\LocalPhotoUploadTransport;
use App\Services\PhotoUploadTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PhotoUploadTransport::class, LocalPhotoUploadTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
