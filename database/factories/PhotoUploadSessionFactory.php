<?php

namespace Database\Factories;

use App\Models\PhotoUploadSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PhotoUploadSession>
 */
class PhotoUploadSessionFactory extends Factory
{
    protected $model = PhotoUploadSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', Str::random(64)),
            'examination_id' => null,
            'expires_at' => now()->addDay(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }
}
