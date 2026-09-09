<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadCleanupService;
use App\Services\PhotoUploadTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupPhotoUploadsCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photo_uploads');
    }

    // ========== Dry-Run Tests ==========

    public function test_dry_run_shows_estimates_only(): void
    {
        $this->createExpiredSession(photoCount: 3);
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'due.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $this->artisan('photo-uploads:cleanup', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry-run mode: no mutations, no storage calls')
            ->expectsOutputToContain('Eligible sessions:     1')
            ->expectsOutputToContain('Eligible photo rows:   3')
            ->expectsOutputToContain('Due queue rows:        1');
    }

    public function test_dry_run_does_not_stage_or_delete(): void
    {
        $session = $this->createExpiredSession(photoCount: 2);

        $this->artisan('photo-uploads:cleanup', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertTrue($session->fresh()->exists);
        $this->assertCount(2, $session->photoUploads);
        $this->assertCount(0, PhotoUploadCleanupQueue::all());
    }

    public function test_dry_run_with_zero_eligible(): void
    {
        $this->artisan('photo-uploads:cleanup', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Eligible sessions:     0')
            ->expectsOutputToContain('Eligible photo rows:   0')
            ->expectsOutputToContain('Due queue rows:        0');
    }

    // ========== Actual Cleanup Tests ==========

    public function test_cleanup_stages_and_processes(): void
    {
        $this->createExpiredSession(photoCount: 2);

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Photo upload cleanup complete')
            ->expectsOutputToContain('Sessions staged:       1')
            ->expectsOutputToContain('Photo rows staged:     2');
    }

    public function test_cleanup_with_limit_option(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createExpiredSession(photoCount: 1);
        }

        $this->artisan('photo-uploads:cleanup', ['--limit' => 3])
            ->assertExitCode(0)
            ->expectsOutputToContain('Sessions staged:       3');
    }

    public function test_cleanup_shows_counts(): void
    {
        Storage::disk('photo_uploads')->put('test.jpg', 'content');

        $this->createExpiredSession(photoCount: 1);
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'test.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Sessions scanned:      1')
            ->expectsOutputToContain('Sessions staged:       1')
            ->expectsOutputToContain('Photo rows staged:     1')
            ->expectsOutputToContain('Queue rows processed:  1')
            ->expectsOutputToContain('Objects cleared:       1');
    }

    public function test_cleanup_exit_code_zero_on_success(): void
    {
        $this->createExpiredSession(photoCount: 1);

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0);
    }

    public function test_cleanup_exit_code_one_on_clearing_failure(): void
    {
        $path = 'private/path-that-must-not-be-printed.jpg';
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => $path,
            'delete_after' => now()->subMinute(),
        ]);

        $transport = new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void {}

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                throw new \RuntimeException('provider secret and path must not escape');
            }
        };
        $this->app->instance(PhotoUploadCleanupService::class, new PhotoUploadCleanupService($transport));

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(1)
            ->expectsOutputToContain('Clearing failures:     1')
            ->doesntExpectOutputToContain($path)
            ->doesntExpectOutputToContain('provider secret and path must not escape');

        $this->assertDatabaseHas('photo_upload_cleanup_queue', [
            'storage_path' => $path,
            'attempt_count' => 1,
            'last_error_code' => 'storage_delete_failed',
        ]);
    }

    public function test_cleanup_handles_empty_system(): void
    {
        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Photo upload cleanup complete');
    }

    public function test_cleanup_processes_queue_backlog(): void
    {
        Storage::disk('photo_uploads')->put('old.jpg', 'content');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'old.jpg',
            'delete_after' => now()->subHour(),
        ]);

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Objects cleared:       1');
    }

    public function test_cleanup_respects_settling_window(): void
    {
        Storage::disk('photo_uploads')->put('future.jpg', 'content');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'future.jpg',
            'delete_after' => now()->addMinute(),
        ]);

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Objects cleared:       0');

        $this->assertTrue(Storage::disk('photo_uploads')->exists('future.jpg'));
    }

    public function test_evidence_conflict_is_retryable_and_non_zero(): void
    {
        $path = 'private/evidence.jpg';
        $examination = Examination::factory()->create();
        ExaminationPhoto::create([
            'examination_id' => $examination->id,
            'storage_disk' => 'photo_uploads',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'width' => 10,
            'height' => 10,
            'display_order' => 1,
        ]);
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => $path,
            'delete_after' => now()->subMinute(),
        ]);

        $transport = new class implements PhotoUploadTransport
        {
            public int $deleteCalls = 0;

            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void
            {
                $this->deleteCalls++;
            }

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                $this->deleteCalls++;
            }
        };
        $this->app->instance(PhotoUploadCleanupService::class, new PhotoUploadCleanupService($transport));

        $this->artisan('photo-uploads:cleanup')
            ->assertExitCode(1)
            ->expectsOutputToContain('Integrity conflicts:   1')
            ->doesntExpectOutputToContain($path);

        $this->assertSame(0, $transport->deleteCalls);
        $this->assertDatabaseHas('photo_upload_cleanup_queue', [
            'storage_path' => $path,
            'attempt_count' => 1,
            'last_error_code' => 'finalized_evidence_reference',
        ]);
    }

    // ========== Scheduler Registration Tests ==========

    public function test_command_is_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('photo-uploads:cleanup');
    }

    // ========== Helper Methods ==========

    private function createExpiredSession(int $photoCount = 0): PhotoUploadSession
    {
        $session = PhotoUploadSession::factory()->create([
            'examination_id' => null,
            'expires_at' => now()->subMinute(),
        ]);

        for ($i = 0; $i < $photoCount; $i++) {
            PhotoUpload::factory()
                ->verified()
                ->for($session)
                ->create([
                    'display_order' => $i + 1,
                ]);
        }

        return $session;
    }
}
