<?php

namespace Tests\Feature\Services;

use App\Exceptions\SubmissionNumberAllocationInsideTransaction;
use App\Exceptions\SubmissionNumberSequenceExhausted;
use App\Services\SubmissionNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmissionNumberGeneratorTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase) is required: RefreshDatabase wraps
    // each test in its own transaction, which would always trip the generator's
    // no-nested-transaction guard.
    use DatabaseMigrations;

    private SubmissionNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new SubmissionNumberGenerator;
    }

    public function test_it_generates_the_first_number_of_a_business_date(): void
    {
        $instant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $this->assertSame('ZB-260908-0001', $this->generator->generate($instant));
    }

    public function test_it_generates_sequential_numbers_on_the_same_business_date(): void
    {
        $instant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $this->assertSame('ZB-260908-0001', $this->generator->generate($instant));
        $this->assertSame('ZB-260908-0002', $this->generator->generate($instant));
        $this->assertSame('ZB-260908-0003', $this->generator->generate($instant));
    }

    public function test_the_sequence_resets_on_the_next_business_date(): void
    {
        $day1 = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');
        $day2 = Carbon::create(2026, 9, 9, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $this->assertSame('ZB-260908-0001', $this->generator->generate($day1));
        $this->assertSame('ZB-260908-0002', $this->generator->generate($day1));

        $this->assertSame('ZB-260909-0001', $this->generator->generate($day2));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function paddingProvider(): iterable
    {
        yield 'one' => [1, '0001'];
        yield 'nine' => [9, '0009'];
        yield 'ten' => [10, '0010'];
        yield 'ninety-nine' => [99, '0099'];
        yield 'one hundred' => [100, '0100'];
        yield 'max' => [9999, '9999'];
    }

    #[DataProvider('paddingProvider')]
    public function test_it_zero_pads_the_sequence(int $previousLastNumber, string $expectedTail): void
    {
        $businessDate = '2026-09-08';

        DB::table('submission_sequences')->insert([
            'sequence_date' => $businessDate,
            'last_number' => $previousLastNumber - 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $this->assertSame("ZB-260908-{$expectedTail}", $this->generator->generate($instant));
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function dateFormattingProvider(): iterable
    {
        yield 'single-digit month and day' => [2026, 1, 5, '260105'];
        yield 'end of year' => [2026, 12, 31, '261231'];
        yield 'the locked example date' => [2026, 9, 8, '260908'];
    }

    #[DataProvider('dateFormattingProvider')]
    public function test_it_formats_the_business_date_as_yymmdd(int $year, int $month, int $day, string $expectedDatePart): void
    {
        $instant = Carbon::create($year, $month, $day, 10, 0, 0, 'Asia/Kuala_Lumpur');

        $this->assertSame("ZB-{$expectedDatePart}-0001", $this->generator->generate($instant));
    }

    public function test_a_utc_instant_after_midnight_kl_time_uses_the_next_kl_business_date(): void
    {
        // 2026-09-08 17:00 UTC is already 2026-09-09 01:00 in Asia/Kuala_Lumpur.
        $instant = Carbon::create(2026, 9, 8, 17, 0, 0, 'UTC');

        $this->assertSame('ZB-260909-0001', $this->generator->generate($instant));
    }

    public function test_a_utc_instant_still_within_the_same_kl_business_date_is_not_shifted(): void
    {
        // 2026-09-08 03:00 UTC is 2026-09-08 11:00 in Asia/Kuala_Lumpur — same calendar date.
        $instant = Carbon::create(2026, 9, 8, 3, 0, 0, 'UTC');

        $this->assertSame('ZB-260908-0001', $this->generator->generate($instant));
    }

    public function test_it_throws_when_the_daily_sequence_is_exhausted(): void
    {
        $businessDate = '2026-09-08';

        DB::table('submission_sequences')->insert([
            'sequence_date' => $businessDate,
            'last_number' => SubmissionNumberGenerator::MAX_DAILY_SEQUENCE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        try {
            $this->generator->generate($instant);
            $this->fail('Expected SubmissionNumberSequenceExhausted to be thrown.');
        } catch (SubmissionNumberSequenceExhausted $exception) {
            $this->assertSame($businessDate, $exception->getBusinessDate());
            $this->assertSame('sequence_exhausted', $exception->getErrorCode());
        }

        $this->assertSame(
            SubmissionNumberGenerator::MAX_DAILY_SEQUENCE,
            DB::table('submission_sequences')->where('sequence_date', $businessDate)->value('last_number'),
        );
    }

    public function test_it_refuses_to_allocate_while_already_inside_a_transaction(): void
    {
        $instant = Carbon::create(2026, 9, 8, 10, 0, 0, 'Asia/Kuala_Lumpur');

        try {
            DB::transaction(function () use ($instant) {
                $this->generator->generate($instant);
            });
            $this->fail('Expected SubmissionNumberAllocationInsideTransaction to be thrown.');
        } catch (SubmissionNumberAllocationInsideTransaction) {
            // expected
        }

        $this->assertNull(
            DB::table('submission_sequences')->where('sequence_date', '2026-09-08')->value('last_number'),
            'No counter row should have been created/incremented by the rejected call.',
        );

        // The connection must remain usable for a normal, non-nested call afterwards.
        $this->assertSame('ZB-260908-0001', $this->generator->generate($instant));
    }

    public function test_database_uniqueness_on_submission_no_is_still_enforced(): void
    {
        $attributes = [
            'submission_no' => 'ZB-260908-0001',
            'agent_name' => 'Test Agent',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-1',
            'agent_company_name' => 'Test Company',
            'agent_station_code' => 'STN-1',
            'location' => 'port',
            'form_type' => 'jkr',
            'container_status' => 'sealed',
            'attending_officer_type' => 'customs',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('examinations')->insert($attributes);

        $this->expectException(QueryException::class);

        DB::table('examinations')->insert($attributes);
    }
}
