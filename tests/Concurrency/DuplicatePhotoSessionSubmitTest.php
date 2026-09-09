<?php

namespace Tests\Concurrency;

use App\Data\ExaminationSubmissionData;
use App\Data\PhotoUploadSessionCredentials;
use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Models\Examination;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Services\ExaminationSubmissionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Verifies real MySQL row-locking on photo_upload_sessions serializes
 * concurrent finalization attempts for the same session so at most one
 * Examination is ever created from it. Run only via
 * phpunit.concurrency.xml against the isolated `zb_examine_test` schema.
 */
class DuplicatePhotoSessionSubmitTest extends TestCase
{
    private const WORKER_COUNT = 8;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The pcntl extension is required for this test.');
        }

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || $database !== 'zb_examine_test') {
            $this->markTestSkipped(
                "Refusing to run: expected the 'mysql' connection pointed at 'zb_examine_test', got connection '{$connection}' / database '{$database}'. Run via: vendor/bin/phpunit -c phpunit.concurrency.xml",
            );
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('submission_sequences')->truncate();
        DB::table('examination_customs_form_numbers')->truncate();
        DB::table('examination_photos')->truncate();
        DB::table('examinations')->truncate();
        DB::table('photo_uploads')->truncate();
        DB::table('photo_upload_sessions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_concurrent_submissions_of_the_same_photo_session_create_at_most_one_examination(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();

        PhotoUpload::factory()->for($session)->create();

        $publicId = $session->public_id;

        $resultsDirectory = storage_path('framework/testing/photo-session-concurrency-'.getmypid());
        @mkdir($resultsDirectory, 0775, true);
        $startFile = $resultsDirectory.'/start';

        // Drop the parent's connection before forking so no PDO handle is shared.
        DB::disconnect();

        $pids = [];

        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                $succeeded = $this->runChild($resultsDirectory, $startFile, $publicId, $token);
                exit($succeeded ? 0 : 1);
            }

            $pids[] = $pid;
        }

        // Every child is forked and polling for the start file; release them together.
        file_put_contents($startFile, '1');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), "Child process {$pid} exited abnormally.");
        }

        DB::reconnect('mysql');

        $results = array_map(
            fn (string $file) => trim(file_get_contents($file)),
            glob($resultsDirectory.'/*.result'),
        );

        $this->assertCount(self::WORKER_COUNT, $results, 'Expected one result file per worker.');

        $successes = array_values(array_filter($results, fn (string $r) => str_starts_with($r, 'OK:')));
        $rejections = array_values(array_filter($results, fn (string $r) => $r === 'REJECTED:session_finalized'));

        $this->assertCount(1, $successes, 'Exactly one submission should succeed for the same photo session.');
        $this->assertCount(self::WORKER_COUNT - 1, $rejections, 'Every other submission must be safely rejected as session_finalized.');

        $this->assertSame(1, Examination::count());
        $this->assertSame(1, DB::table('examination_photos')->count());

        $session->refresh();
        $this->assertSame(Examination::firstOrFail()->id, $session->examination_id);

        array_map('unlink', glob($resultsDirectory.'/*'));
        rmdir($resultsDirectory);
    }

    private function runChild(string $resultsDirectory, string $startFile, string $publicId, string $token): bool
    {
        // Discard the inherited connection; each child gets its own fresh MySQL connection.
        DB::purge('mysql');
        DB::reconnect('mysql');

        while (! file_exists($startFile)) {
            usleep(1000);
        }

        $resultFile = $resultsDirectory.'/'.getmypid().'.result';

        $data = ExaminationSubmissionData::fromValidated([
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::ContainerGateTerminal->value,
            'form_type' => FormType::K1->value,
            'customs_form_numbers' => ['B18112068450'],
            'container_status' => ContainerStatus::Fcl->value,
            'attending_officer_type' => AttendingOfficerType::Customs->value,
        ]);

        $credentials = new PhotoUploadSessionCredentials($publicId, $token);

        try {
            $examination = app(ExaminationSubmissionService::class)->submit($data, $credentials);
            file_put_contents($resultFile, "OK:{$examination->id}");

            return true;
        } catch (PhotoUploadSessionInvalid $e) {
            file_put_contents($resultFile, 'REJECTED:'.$e->getErrorCode());

            return true;
        } catch (Throwable $e) {
            file_put_contents($resultFile, 'ERROR:'.$e::class.':'.$e->getMessage());

            return false;
        }
    }
}
