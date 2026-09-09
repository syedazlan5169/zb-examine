<?php

namespace Database\Factories;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExaminationPhoto>
 */
class ExaminationPhotoFactory extends Factory
{
    protected $model = ExaminationPhoto::class;

    public function definition(): array
    {
        return [
            'examination_id' => Examination::factory(),
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'photo-uploads/'.$this->faker->uuid.'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 500000,
            'width' => 2400,
            'height' => 1800,
            'display_order' => 1,
        ];
    }
}
