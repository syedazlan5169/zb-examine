<?php

namespace App\Services;

use App\Data\ExaminationSubmissionData;
use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Exceptions\SubmissionNumberAllocationInsideTransaction;
use App\Exceptions\SubmissionNumberSequenceExhausted;
use App\Models\Examination;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ExaminationSubmissionService
{
    public function __construct(
        private readonly CustomsFormNumberParser $parser,
        private readonly SubmissionNumberGenerator $submissionNumbers,
    ) {}

    /**
     * Create one complete non-photo examination submission.
     *
     * Must not be called while a database transaction is already active: the
     * submission number is allocated and committed before the examination is
     * persisted, so that an allocated number stays permanently consumed even
     * when persistence subsequently fails (see D018/D019).
     *
     * @throws InvalidCustomsFormNumberInput
     * @throws SubmissionNumberAllocationInsideTransaction
     * @throws SubmissionNumberSequenceExhausted
     */
    public function submit(
        ExaminationSubmissionData $data,
        ?User $user = null,
        ?CarbonInterface $instant = null,
    ): Examination {
        // One physical instant feeds both the business-date number bucket and submitted_at.
        $submittedAt = $instant !== null
            ? CarbonImmutable::instance($instant)->utc()
            : CarbonImmutable::now('UTC');

        // Parse before allocating: invalid input must not consume a submission number.
        $numbers = $this->parser->parse($data->customsFormNumbersInput);

        $submissionNo = $this->submissionNumbers->generate($submittedAt);

        return DB::transaction(function () use ($data, $user, $submissionNo, $submittedAt, $numbers) {
            $examination = Examination::create([
                'submission_no' => $submissionNo,
                'user_id' => $user?->id,

                'agent_name' => $data->agentName,
                'agent_phone' => $data->agentPhone,
                'agent_code' => $data->agentCode,
                'agent_company_name' => $data->agentCompanyName,
                'agent_station_code' => $data->agentStationCode,

                'location' => $data->location,
                'form_type' => $data->formType,
                'form_type_other' => $data->formTypeOther,

                'container_status' => $data->containerStatus,

                'reason' => $data->reason,
                'reason_other' => $data->reasonOther,

                'attending_officer_type' => $data->attendingOfficerType,

                'submitted_at' => $submittedAt,
            ]);

            $rows = [];

            foreach ($numbers as $index => $number) {
                $rows[] = [
                    'number' => $number,
                    'display_order' => $index + 1,
                ];
            }

            $examination->customsFormNumbers()->createMany($rows);

            return $examination;
        });
    }
}
