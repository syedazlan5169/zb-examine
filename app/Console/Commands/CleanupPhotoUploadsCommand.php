<?php

namespace App\Console\Commands;

use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadCleanupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CleanupPhotoUploadsCommand extends Command
{
    protected $signature = 'photo-uploads:cleanup {--dry-run : Preview without mutations or storage calls} {--limit=100 : Maximum sessions to stage per run}';

    protected $description = 'Clean expired photo upload sessions and process deletion queue';

    public function __construct(
        private readonly PhotoUploadCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $sessionLimit = (int) $this->option('limit');

        if ($isDryRun) {
            return $this->handleDryRun(CarbonImmutable::now('UTC'));
        }

        $now = now();

        // Stage expired unfinalized sessions
        $stagingResult = $this->cleanupService->stageExpiredSessions(
            cutoff: $now,
            limit: $sessionLimit,
        );

        // Process queued intents that have reached settle time
        $queueResult = $this->cleanupService->processQueueUntilSettled(
            now: $now,
        );

        // Merge results
        $totalFailures = count($stagingResult->failures) + count($queueResult->failures);
        $allFailures = array_merge($stagingResult->failures, $queueResult->failures);

        // Output counts
        $this->info('Photo upload cleanup complete:');
        $this->line("  Sessions scanned:      {$stagingResult->sessionsScanned}");
        $this->line("  Sessions staged:       {$stagingResult->sessionsStaged}");
        $this->line("  Photo rows staged:     {$stagingResult->photoRowsStaged}");
        $this->line("  Queue rows processed:  {$queueResult->queueRowsDue}");
        $this->line("  Objects cleared:       {$queueResult->objectsCleared}");
        $this->line("  Clearing failures:     {$queueResult->clearingFailures}");
        $this->line("  Integrity conflicts:   {$queueResult->integrityConflicts}");

        if ($totalFailures > 0) {
            $this->warn("Failures encountered ({$totalFailures}):");
            foreach ($allFailures as $failure) {
                $this->line("  - {$failure}");
            }
        }

        // Exit 0 if no failures, 1 if any failure
        return ($stagingResult->clearingFailures > 0 || $queueResult->clearingFailures > 0 || $queueResult->integrityConflicts > 0)
            ? 1
            : 0;
    }

    private function handleDryRun(CarbonImmutable $now): int
    {
        $this->info('Dry-run mode: no mutations, no storage calls.');

        // Estimate eligible sessions
        $eligibleSessions = PhotoUploadSession::query()
            ->where('examination_id', null)
            ->where('expires_at', '<=', $now)
            ->count();

        // Estimate child photo rows
        $eligiblePhotoRows = PhotoUpload::query()
            ->whereIn('photo_upload_session_id',
                PhotoUploadSession::query()
                    ->where('examination_id', null)
                    ->where('expires_at', '<=', $now)
                    ->pluck('id'),
            )
            ->count();

        // Estimate due queue rows
        $dueQueueRows = PhotoUploadCleanupQueue::query()
            ->where('delete_after', '<=', $now)
            ->count();

        $this->line('Estimates (DB only, no storage inspection):');
        $this->line("  Eligible sessions:     {$eligibleSessions}");
        $this->line("  Eligible photo rows:   {$eligiblePhotoRows}");
        $this->line("  Due queue rows:        {$dueQueueRows}");

        return 0;
    }
}
