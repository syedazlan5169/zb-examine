<?php

namespace Tests\Feature\Models;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// Regression only: proves the pre-existing final evidence model/table is
// unaffected by the Step 3B.1 photo-upload domain additions.
class ExaminationPhotoTest extends TestCase
{
    use DatabaseMigrations;

    public function test_examination_photo_persists_relates_and_round_trips_metadata(): void
    {
        $examination = $this->createExamination();

        $photo = ExaminationPhoto::create([
            'examination_id' => $examination->id,
            'storage_disk' => 'local',
            'storage_path' => 'examinations/'.$examination->submission_no.'/1.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 512_000,
            'width' => 2400,
            'height' => 1800,
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('examination_photos', ['id' => $photo->id]);

        $photo->refresh();

        $this->assertTrue($photo->examination->is($examination));
        $this->assertTrue($examination->photos->first()->is($photo));

        $this->assertSame('local', $photo->storage_disk);
        $this->assertSame('examinations/'.$examination->submission_no.'/1.jpg', $photo->storage_path);
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertSame(512_000, $photo->file_size);
        $this->assertSame(2400, $photo->width);
        $this->assertSame(1800, $photo->height);
        $this->assertSame(1, $photo->display_order);
    }

    private function createExamination(): Examination
    {
        return Examination::create([
            'submission_no' => 'ZB-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'agent_name' => 'Test Agent',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Test Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::cases()[0],
            'form_type' => FormType::cases()[0],
            'container_status' => ContainerStatus::cases()[0],
            'attending_officer_type' => AttendingOfficerType::cases()[0],
            'submitted_at' => now(),
        ]);
    }
}
