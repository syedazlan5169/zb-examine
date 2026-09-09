<?php

namespace App\Data;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\ExaminationReason;
use App\Enums\FormType;

/**
 * Caller-supplied examination submission data.
 *
 * Deliberately excludes submission_no, user_id, submitted_at and photos: those
 * are owned by the submission service, never by form input.
 */
final readonly class ExaminationSubmissionData
{
    private function __construct(
        public string $agentName,
        public string $agentPhone,
        public string $agentCode,
        public string $agentCompanyName,
        public string $agentStationCode,
        /** @var list<string> */
        public array $customsFormNumbers,
        public ExaminationLocation $location,
        public FormType $formType,
        public ?string $formTypeOther,
        public ContainerStatus $containerStatus,
        public ?ExaminationReason $reason,
        public ?string $reasonOther,
        public AttendingOfficerType $attendingOfficerType,
    ) {}

    /**
     * Build from validated request data, resolving enum backing strings and
     * canonicalizing the conditional `*_other` fields so stale hidden-form
     * values can never reach persistence.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $formType = FormType::from($validated['form_type']);

        $reason = ($validated['reason'] ?? null) !== null
            ? ExaminationReason::from($validated['reason'])
            : null;

        return new self(
            agentName: self::text($validated['agent_name']),
            agentPhone: self::text($validated['agent_phone']),
            agentCode: self::text($validated['agent_code']),
            agentCompanyName: self::text($validated['agent_company_name']),
            agentStationCode: self::text($validated['agent_station_code']),
            customsFormNumbers: array_values(array_map(
                fn (mixed $value): string => (string) $value,
                $validated['customs_form_numbers'],
            )),
            location: ExaminationLocation::from($validated['location']),
            formType: $formType,
            formTypeOther: $formType === FormType::Other
                ? self::optionalText($validated['form_type_other'] ?? null)
                : null,
            containerStatus: ContainerStatus::from($validated['container_status']),
            reason: $reason,
            reasonOther: $reason === ExaminationReason::Other
                ? self::optionalText($validated['reason_other'] ?? null)
                : null,
            attendingOfficerType: AttendingOfficerType::from($validated['attending_officer_type']),
        );
    }

    private static function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private static function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
