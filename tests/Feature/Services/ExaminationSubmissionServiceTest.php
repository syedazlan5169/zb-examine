<?php

namespace Tests\Feature\Services;

use App\Data\ExaminationSubmissionData;
use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\ExaminationReason;
use App\Enums\FormType;
use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Exceptions\SubmissionNumberAllocationInsideTransaction;
use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\User;
use App\Services\ExaminationSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\TestCase;

class ExaminationSubmissionServiceTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase) is required: RefreshDatabase wraps
    // each test in its own transaction, which would trip the submission-number
    // generator's no-nested-transaction guard.
    use DatabaseMigrations;

    private ExaminationSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ExaminationSubmissionService::class);
    }

    public function test_a_guest_submission_is_persisted_without_a_user(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');

        $examination = $this->service->submit($this->data(), null, $instant);

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertNull($persisted->user_id);
        $this->assertSame('ZB-260908-0001', $persisted->submission_no);

        $this->assertSame('Ali bin Abu', $persisted->agent_name);
        $this->assertSame('0123456789', $persisted->agent_phone);
        $this->assertSame('AGT-001', $persisted->agent_code);
        $this->assertSame('Syarikat Penghantaran Sdn Bhd', $persisted->agent_company_name);
        $this->assertSame('STN-01', $persisted->agent_station_code);

        $this->assertSame(ExaminationLocation::ContainerGateTerminal, $persisted->location);
        $this->assertSame(FormType::K1, $persisted->form_type);
        $this->assertSame(ContainerStatus::Fcl, $persisted->container_status);
        $this->assertSame(AttendingOfficerType::Customs, $persisted->attending_officer_type);

        $this->assertSame('2026-09-08 02:00:00', $persisted->submitted_at->utc()->toDateTimeString());

        $this->assertSame(
            ['B18112068450'],
            $persisted->customsFormNumbers->pluck('number')->all(),
        );
    }

    public function test_a_registered_agent_submission_stores_submitted_values_not_the_user_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Stale Profile Name',
            'phone' => '0000000000',
            'agent_code' => 'STALE-CODE',
            'company_name' => 'Stale Company Sdn Bhd',
            'station_code' => 'STALE-STN',
        ]);

        $examination = $this->service->submit($this->data(), $user);

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame($user->id, $persisted->user_id);

        $this->assertSame('Ali bin Abu', $persisted->agent_name);
        $this->assertSame('0123456789', $persisted->agent_phone);
        $this->assertSame('AGT-001', $persisted->agent_code);
        $this->assertSame('Syarikat Penghantaran Sdn Bhd', $persisted->agent_company_name);
        $this->assertSame('STN-01', $persisted->agent_station_code);
    }

    public function test_it_expands_shorthand_customs_form_numbers_into_ordered_child_rows(): void
    {
        $examination = $this->service->submit(
            $this->data(['customs_form_numbers' => 'B18112068450,51,52,53']),
        );

        $rows = ExaminationCustomsFormNumber::where('examination_id', $examination->getKey())
            ->orderBy('display_order')
            ->get();

        $this->assertSame(
            [
                ['B18112068450', 1],
                ['B18112068451', 2],
                ['B18112068452', 3],
                ['B18112068453', 4],
            ],
            $rows->map(fn (ExaminationCustomsFormNumber $row) => [$row->number, $row->display_order])->all(),
        );
    }

    public function test_it_persists_mixed_complete_and_shorthand_customs_form_numbers_in_order(): void
    {
        $examination = $this->service->submit(
            $this->data(['customs_form_numbers' => 'B18112068450,51,B18112068570,71']),
        );

        $rows = ExaminationCustomsFormNumber::where('examination_id', $examination->getKey())
            ->orderBy('display_order')
            ->get();

        $this->assertSame(
            [
                ['B18112068450', 1],
                ['B18112068451', 2],
                ['B18112068570', 3],
                ['B18112068571', 4],
            ],
            $rows->map(fn (ExaminationCustomsFormNumber $row) => [$row->number, $row->display_order])->all(),
        );
    }

    public function test_invalid_customs_form_input_propagates_and_consumes_no_submission_number(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');

        try {
            $this->service->submit($this->data(['customs_form_numbers' => 'NOT-A-NUMBER']), null, $instant);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('invalid_number', $exception->getErrorCode());
        }

        $this->assertSame(0, Examination::count());
        $this->assertNull(
            DB::table('submission_sequences')->where('sequence_date', '2026-09-08')->value('last_number'),
            'Parsing must happen before allocation so invalid input wastes no sequence number.',
        );
    }

    public function test_wrapping_submit_in_an_outer_transaction_is_rejected_before_anything_is_allocated(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');

        // The generator's guard is the single enforcement mechanism; the service adds none.
        try {
            DB::transaction(function () use ($instant) {
                $this->service->submit($this->data(['customs_form_numbers' => 'B18112068450,51']), null, $instant);
            });
            $this->fail('Expected SubmissionNumberAllocationInsideTransaction to be thrown.');
        } catch (SubmissionNumberAllocationInsideTransaction) {
            // expected
        }

        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertNull(
            $this->lastNumberFor('2026-09-08'),
            'A rejected wrapped submission must not create or increment a sequence counter.',
        );

        $examination = $this->service->submit($this->data(), null, $instant);

        $this->assertSame('ZB-260908-0001', $examination->submission_no, 'The rejected attempt must not have consumed a number.');
        $this->assertSame(1, Examination::count());
    }

    public function test_a_parent_persistence_failure_rolls_back_but_permanently_consumes_the_number(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');
        $event = 'eloquent.creating: '.Examination::class;

        // LogicException, not RuntimeException: PHPUnit's AssertionFailedError is a
        // RuntimeException, so a broad catch here could swallow $this->fail().
        Event::listen($event, function (): void {
            throw new LogicException('parent persistence failed');
        });

        try {
            $this->service->submit($this->data(['customs_form_numbers' => 'B18112068450,51']), null, $instant);
            $this->fail('Expected LogicException to be thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('parent persistence failed', $exception->getMessage());
        }

        Event::forget($event);

        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertSame(1, $this->lastNumberFor('2026-09-08'));

        $examination = $this->service->submit($this->data(), null, $instant);

        $this->assertSame('ZB-260908-0002', $examination->submission_no, 'The failed allocation must leave a permanent gap.');
        $this->assertSame(1, Examination::count());
    }

    public function test_a_child_row_failure_rolls_back_the_parent_and_earlier_children(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');
        $event = 'eloquent.creating: '.ExaminationCustomsFormNumber::class;

        $attempts = 0;

        Event::listen($event, function () use (&$attempts): void {
            $attempts++;

            if ($attempts >= 2) {
                throw new LogicException('child persistence failed');
            }
        });

        try {
            $this->service->submit($this->data(['customs_form_numbers' => 'B18112068450,51,52']), null, $instant);
            $this->fail('Expected LogicException to be thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('child persistence failed', $exception->getMessage());
        }

        Event::forget($event);

        $this->assertSame(2, $attempts, 'The first child must have been inserted before the second one failed.');
        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertSame(1, $this->lastNumberFor('2026-09-08'));

        $examination = $this->service->submit($this->data(), null, $instant);

        $this->assertSame('ZB-260908-0002', $examination->submission_no);
    }

    public function test_test_scoped_failure_listeners_do_not_leak_between_tests(): void
    {
        $examination = $this->service->submit($this->data());

        $this->assertFalse(Model::getEventDispatcher()->hasListeners('eloquent.creating: '.Examination::class));
        $this->assertTrue($examination->exists);
    }

    public function test_form_type_other_is_nulled_when_the_form_type_is_not_other(): void
    {
        $examination = $this->service->submit($this->data([
            'form_type' => FormType::K1->value,
            'form_type_other' => 'Stale hidden value',
        ]));

        $this->assertNull(Examination::findOrFail($examination->getKey())->form_type_other);
    }

    public function test_form_type_other_is_retained_when_the_form_type_is_other(): void
    {
        $examination = $this->service->submit($this->data([
            'form_type' => FormType::Other->value,
            'form_type_other' => 'Borang khas',
        ]));

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame(FormType::Other, $persisted->form_type);
        $this->assertSame('Borang khas', $persisted->form_type_other);
    }

    public function test_reason_other_is_nulled_when_the_reason_is_null(): void
    {
        $examination = $this->service->submit($this->data([
            'reason' => null,
            'reason_other' => 'Stale hidden value',
        ]));

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertNull($persisted->reason);
        $this->assertNull($persisted->reason_other);
    }

    public function test_reason_other_is_nulled_when_the_reason_is_not_other(): void
    {
        $examination = $this->service->submit($this->data([
            'reason' => ExaminationReason::Drawback->value,
            'reason_other' => 'Stale hidden value',
        ]));

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame(ExaminationReason::Drawback, $persisted->reason);
        $this->assertNull($persisted->reason_other);
    }

    public function test_reason_other_is_retained_when_the_reason_is_other(): void
    {
        $examination = $this->service->submit($this->data([
            'reason' => ExaminationReason::Other->value,
            'reason_other' => 'Sebab lain',
        ]));

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame(ExaminationReason::Other, $persisted->reason);
        $this->assertSame('Sebab lain', $persisted->reason_other);
    }

    public function test_submitted_at_stores_exactly_the_injected_instant_in_utc(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 10, 30, 15, 'Asia/Kuala_Lumpur');

        $examination = $this->service->submit($this->data(), null, $instant);

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame('2026-09-08 02:30:15', $persisted->submitted_at->utc()->toDateTimeString());
        $this->assertTrue($persisted->submitted_at->equalTo($instant));
    }

    public function test_the_business_date_and_submitted_at_come_from_the_same_instant_across_midnight(): void
    {
        // 2026-09-08 17:00 UTC is already 2026-09-09 01:00 in Asia/Kuala_Lumpur.
        $instant = CarbonImmutable::create(2026, 9, 8, 17, 0, 0, 'UTC');

        $examination = $this->service->submit($this->data(), null, $instant);

        $persisted = Examination::findOrFail($examination->getKey());

        $this->assertSame('ZB-260909-0001', $persisted->submission_no);
        $this->assertSame('2026-09-08 17:00:00', $persisted->submitted_at->utc()->toDateTimeString());
    }

    public function test_consecutive_submissions_receive_sequential_submission_numbers(): void
    {
        $instant = CarbonImmutable::create(2026, 9, 8, 2, 0, 0, 'UTC');

        $this->assertSame('ZB-260908-0001', $this->service->submit($this->data(), null, $instant)->submission_no);
        $this->assertSame('ZB-260908-0002', $this->service->submit($this->data(), null, $instant)->submission_no);
        $this->assertSame('ZB-260908-0003', $this->service->submit($this->data(), null, $instant)->submission_no);

        $this->assertSame(3, Examination::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function data(array $overrides = []): ExaminationSubmissionData
    {
        return ExaminationSubmissionData::fromValidated(array_merge([
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Penghantaran Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'customs_form_numbers' => 'B18112068450',
            'location' => ExaminationLocation::ContainerGateTerminal->value,
            'form_type' => FormType::K1->value,
            'form_type_other' => null,
            'container_status' => ContainerStatus::Fcl->value,
            'reason' => null,
            'reason_other' => null,
            'attending_officer_type' => AttendingOfficerType::Customs->value,
        ], $overrides));
    }

    private function lastNumberFor(string $businessDate): ?int
    {
        $lastNumber = DB::table('submission_sequences')
            ->where('sequence_date', $businessDate)
            ->value('last_number');

        return $lastNumber === null ? null : (int) $lastNumber;
    }
}
