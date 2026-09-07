<?php

namespace Tests\Concurrency;

use App\Services\SubmissionNumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Verifies real MySQL row-locking serializes concurrent submission-number
 * allocations. Run only via phpunit.concurrency.xml against the isolated
 * `zb_examine_test` schema — never against the dev database.
 */
class SubmissionNumberGeneratorConcurrencyTest extends TestCase
{
    private const WORKER_COUNT = 20;

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

        DB::table('submission_sequences')->truncate();
    }

    public function test_concurrent_allocations_for_the_same_business_date_are_unique_and_sequential(): void
    {
        $businessDate = '2026-09-08';
        $businessInstant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $resultsDirectory = storage_path('framework/testing/concurrency-'.getmypid());
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
                $succeeded = $this->runChild($resultsDirectory, $startFile, $businessInstant);
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

        $numbers = [];
        foreach ($results as $result) {
            $this->assertStringStartsWith('OK:', $result, "A worker failed: {$result}");
            $numbers[] = substr($result, 3);
        }

        $this->assertCount(
            self::WORKER_COUNT,
            array_unique($numbers),
            'Duplicate submission numbers were allocated concurrently.',
        );

        sort($numbers);
        $expected = array_map(
            fn (int $n) => sprintf('ZB-260908-%04d', $n),
            range(1, self::WORKER_COUNT),
        );

        $this->assertSame($expected, $numbers, 'Allocated numbers are not a complete, correctly ordered sequence.');

        $this->assertSame(
            self::WORKER_COUNT,
            DB::table('submission_sequences')->where('sequence_date', $businessDate)->value('last_number'),
        );

        array_map('unlink', glob($resultsDirectory.'/*'));
        rmdir($resultsDirectory);
    }

    private function runChild(string $resultsDirectory, string $startFile, Carbon $businessInstant): bool
    {
        // Discard the inherited connection; each child gets its own fresh MySQL connection.
        DB::purge('mysql');
        DB::reconnect('mysql');

        while (! file_exists($startFile)) {
            usleep(1000);
        }

        $resultFile = $resultsDirectory.'/'.getmypid().'.result';

        try {
            $number = (new SubmissionNumberGenerator)->generate($businessInstant);
            file_put_contents($resultFile, "OK:{$number}");

            return true;
        } catch (Throwable $e) {
            file_put_contents($resultFile, 'ERROR:'.$e::class.':'.$e->getMessage());

            return false;
        }
    }
}
