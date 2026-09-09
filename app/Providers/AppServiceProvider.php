<?php

namespace App\Providers;

use App\Models\ExaminationPhoto;
use App\Policies\ExaminationPhotoPolicy;
use App\Services\AwsSpacesObjectClient;
use App\Services\AwsSpacesPutPresigner;
use App\Services\DirectPhotoUploadAuthorizer;
use App\Services\LocalPhotoUploadTransport;
use App\Services\PhotoUploadTransport;
use App\Services\SpacesObjectClient;
use App\Services\SpacesPhotoUploadAuthorizer;
use App\Services\SpacesPutPresigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PhotoUploadTransport::class, LocalPhotoUploadTransport::class);
        $this->app->bind(DirectPhotoUploadAuthorizer::class, SpacesPhotoUploadAuthorizer::class);
        $this->app->bind(SpacesObjectClient::class, AwsSpacesObjectClient::class);
        $this->app->bind(SpacesPutPresigner::class, AwsSpacesPutPresigner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ExaminationPhoto::class, ExaminationPhotoPolicy::class);

        RateLimiter::for('login', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        $presignTtl = (int) config('zb-examine.photo_upload_presign_ttl_seconds', 300);
        $settleSeconds = (int) config('zb-examine.photo_cleanup_settle_seconds', 3600);

        if ($presignTtl <= 0 || $presignTtl >= $settleSeconds) {
            throw new \RuntimeException(
                'PHOTO_UPLOAD_PRESIGN_TTL must be positive and less than PHOTO_CLEANUP_SETTLE_SECONDS.'
            );
        }
    }
}
