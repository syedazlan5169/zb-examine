<?php

namespace Tests\Feature;

use App\Exceptions\SpacesGetPresigningException;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\User;
use App\Services\FinalizedEvidenceAccessService;
use App\Services\SpacesGetPresigner;
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
        $this->app->instance(SpacesGetPresigner::class, new FakeSpacesGetPresigner);
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

    public function test_officer_can_redirect_to_finalized_spaces_evidence(): void
    {
        $examination = Examination::factory()->create();
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'/0123456789abcdef0123456789abcdef0123456789abcdef.jpg',
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertStatus(302)
            ->assertRedirect('https://spaces.example.test/signed-get');

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertContent('');
    }

    public function test_admin_can_redirect_to_finalized_spaces_evidence(): void
    {
        [$examination, $photo] = $this->spacesPhoto();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertRedirect('https://spaces.example.test/signed-get');
    }

    public function test_invalid_spaces_paths_are_not_found_before_presigning(): void
    {
        $examination = Examination::factory()->create();
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-upload-staging/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'.jpg',
        ]);

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertNotFound();

        $this->assertFalse($this->app->make(SpacesGetPresigner::class)->called);
    }

    public function test_preview_ttl_is_clamped_to_safe_bounds(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);

        config(['zb-examine.photo_preview_presign_ttl_seconds' => 1]);
        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));
        $this->assertSame(60, $presigner->ttlSeconds);

        config(['zb-examine.photo_preview_presign_ttl_seconds' => 999]);
        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));
        $this->assertSame(300, $presigner->ttlSeconds);
    }

    public function test_preview_ttl_accepts_valid_integers_and_integer_strings(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);

        foreach ([
            [120, 120],
            ['120', 120],
            [10, 60],
            [500, 300],
            [-1, 60],
        ] as [$configured, $expected]) {
            config(['zb-examine.photo_preview_presign_ttl_seconds' => $configured]);

            $this->actingAs(User::factory()->officer()->create())
                ->get(route('examinations.photos.preview', [$examination, $photo]));

            $this->assertSame($expected, $presigner->ttlSeconds, 'Unexpected TTL for '.var_export($configured, true));
        }
    }

    public function test_preview_ttl_uses_default_for_missing_and_malformed_values(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);

        $configuredValues = config('zb-examine');
        unset($configuredValues['photo_preview_presign_ttl_seconds']);
        config(['zb-examine' => $configuredValues]);

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));
        $this->assertSame(120, $presigner->ttlSeconds);

        foreach (['', '   ', 'abc', '120.5'] as $configured) {
            config(['zb-examine.photo_preview_presign_ttl_seconds' => $configured]);

            $this->actingAs(User::factory()->officer()->create())
                ->get(route('examinations.photos.preview', [$examination, $photo]));

            $this->assertSame(120, $presigner->ttlSeconds, 'Unexpected TTL for '.var_export($configured, true));
        }
    }

    public function test_expected_presigning_exception_is_an_opaque_service_unavailable_response(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);
        $presigner->exception = new SpacesGetPresigningException;

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));

        $response->assertStatus(503)
            ->assertDontSee('Spaces GET presigning failed.')
            ->assertDontSee($photo->storage_path);
    }

    public function test_unexpected_programming_error_escapes_the_access_service(): void
    {
        [, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);
        $presigner->exception = new \Error('programming defect');

        $this->expectException(\Error::class);
        $this->app->make(FinalizedEvidenceAccessService::class)->open($photo);
    }

    public function test_presigner_failure_is_an_opaque_service_unavailable_response(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);
        $presigner->exception = new SpacesGetPresigningException;

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]));

        $response->assertStatus(503)
            ->assertDontSee('Spaces GET presigning failed.')
            ->assertDontSee($photo->storage_path);
    }

    public function test_spaces_request_parameters_cannot_change_the_signed_object(): void
    {
        [$examination, $photo] = $this->spacesPhoto();
        $presigner = $this->app->make(SpacesGetPresigner::class);

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.photos.preview', [$examination, $photo]).'?disk=other&path=staging.jpg&bucket=other&key=other.jpg')
            ->assertRedirect('https://spaces.example.test/signed-get');

        $this->assertSame($photo->storage_path, $presigner->objectKey);
        $this->assertSame('evidence-'.$photo->id.'.jpg', $presigner->filename);
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

    /** @return array{0: Examination, 1: ExaminationPhoto} */
    private function spacesPhoto(): array
    {
        $examination = Examination::factory()->create();
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'/0123456789abcdef0123456789abcdef0123456789abcdef.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        return [$examination, $photo];
    }
}

final class FakeSpacesGetPresigner implements SpacesGetPresigner
{
    public bool $called = false;

    public ?\Throwable $exception = null;

    public ?string $objectKey = null;

    public ?string $filename = null;

    public int $ttlSeconds = 0;

    public function presign(string $objectKey, int $ttlSeconds, string $filename): string
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $this->called = true;
        $this->objectKey = $objectKey;
        $this->ttlSeconds = $ttlSeconds;
        $this->filename = $filename;

        return 'https://spaces.example.test/signed-get';
    }
}
