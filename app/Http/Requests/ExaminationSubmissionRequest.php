<?php

namespace App\Http\Requests;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\ExaminationReason;
use App\Enums\FormType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExaminationSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize request state the DTO/validation rules require before they run.
     */
    protected function prepareForValidation(): void
    {
        // The DTO expects null for "no reason", never an empty string from a <select> placeholder.
        $reason = $this->input('reason');
        $reason = $reason === '' ? null : $reason;

        $customsFormNumbers = $this->input('customs_form_numbers');

        $this->merge([
            'reason' => $reason,
            'form_type_other' => $this->input('form_type') === FormType::Other->value
                ? $this->input('form_type_other')
                : null,
            'reason_other' => $reason === ExaminationReason::Other->value
                ? $this->input('reason_other')
                : null,
            // Trim only — empty/duplicate entries must still fail validation, never be silently dropped.
            'customs_form_numbers' => is_array($customsFormNumbers)
                ? array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $customsFormNumbers)
                : $customsFormNumbers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_name' => ['required', 'string', 'max:255'],
            'agent_phone' => ['required', 'string', 'max:30'],
            'agent_code' => ['required', 'string', 'max:50'],
            'agent_company_name' => ['required', 'string', 'max:255'],
            'agent_station_code' => ['required', 'string', 'max:50'],

            'location' => ['required', 'string', Rule::enum(ExaminationLocation::class)],

            'form_type' => ['required', 'string', Rule::enum(FormType::class)],
            'form_type_other' => ['nullable', 'string', 'max:255', 'required_if:form_type,other'],

            'customs_form_numbers' => ['required', 'array', 'min:1', 'max:255'],
            'customs_form_numbers.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],

            'container_status' => ['required', 'string', Rule::enum(ContainerStatus::class)],

            'reason' => ['nullable', 'string', Rule::enum(ExaminationReason::class)],
            'reason_other' => ['nullable', 'string', 'max:255', 'required_if:reason,other'],

            'attending_officer_type' => ['required', 'string', Rule::enum(AttendingOfficerType::class)],

            // Basic shape only — domain/session validity belongs to PhotoUploadSessionFinalizer,
            // never a DB `exists:` rule here (would leak session-existence semantics via timing).
            'photo_upload_session_public_id' => ['required', 'string', 'max:255'],
            'photo_upload_token' => ['required', 'string', 'max:255'],
        ];
    }
}
