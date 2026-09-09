<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExaminationPhotoPreviewTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_PUBLIC_ID = '01JAR7Z5G2R9D7RQP6H9P6FS03';

    private const PHOTO_PUBLIC_ID = '01JAR7Z5G2R9D7RQP6H9P6FS04';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photo_uploads');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$examination, $photo] = $this->storedLocalPhoto();

        $this->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertRedirect(route('login'));
    }

    public function test_agent_is_forbidden(): void
    {
        [$examination, $photo] = $this->storedLocalPhoto();

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertForbidden();
    }

    public function test_officer_can_stream_a_finalized_local_jpeg_with_private_inline_headers(): void
    {
        [$examination, $photo, $bytes] = $this->storedLocalPhoto();

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', "inline; filename=\"evidence-{$photo->id}.jpg\"")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_admin_can_stream_a_finalized_local_jpeg(): void
    {
        [$examination, $photo, $bytes] = $this->storedLocalPhoto();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_photo_from_another_examination_is_not_found(): void
    {
        [$otherExamination] = $this->storedLocalPhoto();
        [, $photo] = $this->storedLocalPhoto();

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$otherExamination, $photo]))
            ->assertNotFound();
    }

    public function test_missing_local_object_is_not_found_without_storage_disclosure(): void
    {
        $examination = Examination::factory()->create();
        $photo = $this->localPhoto($examination);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));

        $response->assertNotFound()
            ->assertDontSee('photo_uploads')
            ->assertDontSee($photo->storage_path);
    }

    public function test_invalid_local_paths_and_unknown_disks_are_not_found(): void
    {
        $examination = Examination::factory()->create();

        foreach ([
            ['photo_uploads', 'photo-upload-staging/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'.jpg'],
            ['photo_uploads', '../private/evidence.jpg'],
            ['unrecognized_private_disk', 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'.jpg'],
        ] as [$disk, $path]) {
            $photo = ExaminationPhoto::factory()->for($examination)->create([
                'storage_disk' => $disk,
                'storage_path' => $path,
            ]);

            $this->actingAs(User::factory()->officer()->create())
                ->get(route('examinations.photos.preview', [$examination, $photo]))
                ->assertNotFound();
        }
    }

    public function test_spaces_evidence_is_temporarily_unavailable(): void
    {
        $examination = Examination::factory()->create();
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'/0123456789abcdef0123456789abcdef0123456789abcdef.jpg',
        ]);

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertStatus(503);
    }

    public function test_query_parameters_cannot_change_the_retrieved_object(): void
    {
        [$examination, $photo, $bytes] = $this->storedLocalPhoto();

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]).'?disk=photo_uploads_spaces&path=other.jpg');

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
    }

    /** @return array{0: Examination, 1: ExaminationPhoto, 2: string} */
    private function storedLocalPhoto(): array
    {
        $examination = Examination::factory()->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        return [$examination, $photo, $bytes];
    }

    private function localPhoto(Examination $examination): ExaminationPhoto
    {
        return ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'.jpg',
            'mime_type' => 'image/jpeg',
        ]);
    }
}
