<?php

namespace Tests\Feature\Services;

use App\Data\PhotoUploadSessionCredentials;
use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadSessionFinalizer;
use App\Services\PhotoUploadTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoUploadSessionFinalizerTest extends TestCase
{
    use DatabaseMigrations;

    public function test_precheck_passes_for_a_valid_single_verified_photo_session(): void
    {
        $credentials = $this->issueSessionWithPhotos(1);

        app(PhotoUploadSessionFinalizer::class)->precheck($credentials);

        $this->addToAssertionCount(1);
    }

    public function test_precheck_rejects_zero_photos(): void
    {
        $credentials = $this->issueSessionWithPhotos(0);

        $this->expectException(PhotoUploadInvalid::class);
        $this->expectExceptionMessage('photo_count_invalid');

        app(PhotoUploadSessionFinalizer::class)->precheck($credentials);
    }

    public function test_precheck_rejects_a_pending_unverified_photo(): void
    {
        $session = PhotoUploadSession::factory()->create();
        PhotoUpload::factory()->pending()->for($session)->create();

        $credentials = $this->credentialsFor($session);

        $this->expectException(PhotoUploadInvalid::class);
        $this->expectExceptionMessage('unverified_photo_pending');

        app(PhotoUploadSessionFinalizer::class)->precheck($credentials);
    }

    public function test_precheck_rejects_an_already_finalized_session(): void
    {
        $examination = $this->createExamination();
        $session = PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);
        PhotoUpload::factory()->for($session)->create();

        $credentials = $this->credentialsFor($session);

        $this->expectException(PhotoUploadSessionInvalid::class);
        $this->expectExceptionMessage('session_finalized');

        app(PhotoUploadSessionFinalizer::class)->precheck($credentials);
    }

    public function test_lock_requires_an_active_transaction_like_context_and_rechecks_authoritatively(): void
    {
        $credentials = $this->issueSessionWithPhotos(3);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        DB::transaction(function () use ($finalizer, $credentials) {
            $lockedSession = $finalizer->lock($credentials);

            $this->assertCount(3, $lockedSession->photoUploads);
        });
    }

    /**
     * Precheck race (D022, accepted/documented): precheck passing is advisory
     * only. If the session is finalized between precheck and the locked
     * transaction, lock() must still catch it authoritatively.
     */
    public function test_precheck_race_is_caught_authoritatively_by_lock(): void
    {
        $credentials = $this->issueSessionWithPhotos(1);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        $finalizer->precheck($credentials);

        // Simulate a concurrent request finalizing the session in between.
        $session = PhotoUploadSession::where('public_id', $credentials->publicId)->firstOrFail();
        $session->examination_id = $this->createExamination()->id;
        $session->save();

        $this->expectException(PhotoUploadSessionInvalid::class);
        $this->expectExceptionMessage('session_finalized');

        DB::transaction(fn () => $finalizer->lock($credentials));
    }

    public function test_lock_never_reuses_pending_photos_created_after_precheck(): void
    {
        $session = PhotoUploadSession::factory()->create();
        PhotoUpload::factory()->for($session)->create();

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        $finalizer->precheck($credentials);

        // A second, still-pending photo appears only after precheck ran.
        PhotoUpload::factory()->pending()->for($session)->create();

        $this->expectException(PhotoUploadInvalid::class);
        $this->expectExceptionMessage('unverified_photo_pending');

        DB::transaction(fn () => $finalizer->lock($credentials));
    }

    public function test_lock_rejects_verified_direct_photo_still_in_staging_path(): void
    {
        $session = PhotoUploadSession::factory()->create();
        PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-upload-staging/abc/def.jpg',
            'verified_at' => now(),
        ]);

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        $this->expectException(PhotoUploadInvalid::class);
        $this->expectExceptionMessage('unverified_photo_pending');

        DB::transaction(fn () => $finalizer->lock($credentials));
    }

    public function test_lock_accepts_a_verified_direct_photo_with_a_sealed_path(): void
    {
        $session = PhotoUploadSession::factory()->create();
        PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/abc/def/sealed-id.jpg',
            'verified_at' => now(),
        ]);

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        DB::transaction(fn () => $finalizer->lock($credentials));

        $this->addToAssertionCount(1);
    }

    public function test_finalization_of_a_verified_direct_sealed_photo_performs_zero_storage_calls(): void
    {
        $session = PhotoUploadSession::factory()->create();
        $upload = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/abc/def/sealed-id.jpg',
            'verified_at' => now(),
        ]);

        $this->app->bind(PhotoUploadTransport::class, fn () => new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void
            {
                throw new \RuntimeException('finalization must never touch storage');
            }

            public function verify(PhotoUpload $upload): array
            {
                throw new \RuntimeException('finalization must never touch storage');
            }

            public function delete(PhotoUpload $upload): void
            {
                throw new \RuntimeException('finalization must never touch storage');
            }

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                throw new \RuntimeException('finalization must never touch storage');
            }
        });

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);
        $examination = $this->createExamination();

        DB::transaction(function () use ($finalizer, $credentials, $examination): void {
            $lockedSession = $finalizer->lock($credentials);
            $finalizer->attachPhotos($examination, $lockedSession);
        });

        $photo = ExaminationPhoto::where('examination_id', $examination->id)->firstOrFail();
        $this->assertSame($upload->storage_path, $photo->storage_path);
    }

    public function test_attach_photos_creates_contiguous_display_order_from_gapped_source_rows(): void
    {
        $session = PhotoUploadSession::factory()->create();
        PhotoUpload::factory()->for($session)->create(['display_order' => 2]);
        PhotoUpload::factory()->for($session)->create(['display_order' => 5]);
        PhotoUpload::factory()->for($session)->create(['display_order' => 9]);

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);

        $examination = $this->createExamination();

        DB::transaction(function () use ($finalizer, $credentials, $examination) {
            $lockedSession = $finalizer->lock($credentials);
            $finalizer->attachPhotos($examination, $lockedSession);
        });

        $orders = ExaminationPhoto::where('examination_id', $examination->id)
            ->orderBy('display_order')
            ->pluck('display_order')
            ->all();

        $this->assertSame([1, 2, 3], $orders);
    }

    public function test_attach_photos_copies_metadata_exactly_and_claims_the_session(): void
    {
        $session = PhotoUploadSession::factory()->create();
        $upload = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'photo-uploads/example/example.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 123456,
            'width' => 1800,
            'height' => 2400,
        ]);

        $credentials = $this->credentialsFor($session);
        $finalizer = app(PhotoUploadSessionFinalizer::class);
        $examination = $this->createExamination();

        DB::transaction(function () use ($finalizer, $credentials, $examination) {
            $lockedSession = $finalizer->lock($credentials);
            $finalizer->attachPhotos($examination, $lockedSession);
        });

        $photo = ExaminationPhoto::where('examination_id', $examination->id)->firstOrFail();

        $this->assertSame($upload->storage_disk, $photo->storage_disk);
        $this->assertSame($upload->storage_path, $photo->storage_path);
        $this->assertSame($upload->mime_type, $photo->mime_type);
        $this->assertSame($upload->file_size, $photo->file_size);
        $this->assertSame($upload->width, $photo->width);
        $this->assertSame($upload->height, $photo->height);

        $session->refresh();
        $this->assertSame($examination->id, $session->examination_id);
    }

    private function issueSessionWithPhotos(int $count): PhotoUploadSessionCredentials
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();

        PhotoUpload::factory()->for($session)->count($count)->create();

        return new PhotoUploadSessionCredentials($session->public_id, $token);
    }

    private function credentialsFor(PhotoUploadSession $session): PhotoUploadSessionCredentials
    {
        $token = Str::random(64);

        $session->token_hash = hash('sha256', $token);
        $session->save();

        return new PhotoUploadSessionCredentials($session->public_id, $token);
    }

    private function createExamination(): Examination
    {
        return Examination::create([
            'submission_no' => 'ZB-000000-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT).'-'.Str::random(6),
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::ContainerGateTerminal,
            'form_type' => FormType::K1,
            'container_status' => ContainerStatus::Fcl,
            'attending_officer_type' => AttendingOfficerType::Customs,
            'submitted_at' => now(),
        ]);
    }
}
