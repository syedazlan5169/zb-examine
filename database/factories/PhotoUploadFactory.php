<?php

namespace Database\Factories;

use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PhotoUpload>
 */
class PhotoUploadFactory extends Factory
{
    protected $model = PhotoUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'photo_upload_session_id' => PhotoUploadSession::factory(),
            'public_id' => (string) Str::ulid(),
            'storage_disk' => 'local',
            'storage_path' => 'photo-uploads/'.Str::ulid().'/'.Str::ulid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $this->faker->numberBetween(200_000, 800_000),
            'width' => 2400,
            'height' => 1800,
            'verified_at' => now(),
            'display_order' => 1,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => null,
        ]);
    }
}
