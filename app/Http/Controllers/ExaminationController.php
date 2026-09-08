<?php

namespace App\Http\Controllers;

use App\Data\ExaminationSubmissionData;
use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Exceptions\SubmissionNumberSequenceExhausted;
use App\Http\Requests\ExaminationSubmissionRequest;
use App\Services\ExaminationSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ExaminationController extends Controller
{
    /** Machine-readable parser codes with a dedicated translation; anything else uses a generic message. */
    private const CUSTOMS_FORM_NUMBER_ERROR_CODES = [
        'empty_input',
        'empty_token',
        'invalid_number',
        'missing_base_number',
        'duplicate_number',
    ];

    public function create(): View
    {
        // Starting a fresh submission ends the previous temporary success state.
        session()->forget('examination_success');

        return view('examinations.create');
    }

    public function store(ExaminationSubmissionRequest $request, ExaminationSubmissionService $service): RedirectResponse
    {
        $data = ExaminationSubmissionData::fromValidated($request->validated());

        try {
            $examination = $service->submit($data, auth()->user());
        } catch (InvalidCustomsFormNumberInput $e) {
            return back()->withInput()->withErrors([
                'customs_form_numbers' => $this->customsFormNumberErrorMessage($e),
            ]);
        } catch (SubmissionNumberSequenceExhausted $e) {
            Log::warning('Submission number sequence exhausted', ['business_date' => $e->getBusinessDate()]);

            return back()->withInput()->with('submission_error', __('examination.errors.sequence_exhausted'));
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('submission_error', __('examination.errors.generic_failure'));
        }

        session(['examination_success' => ['submission_no' => $examination->submission_no]]);

        return redirect()->route('examinations.success');
    }

    public function success(): View|RedirectResponse
    {
        $success = session('examination_success');

        if (! $success) {
            return redirect()->route('examinations.create');
        }

        return view('examinations.success', ['submissionNo' => $success['submission_no']]);
    }

    private function customsFormNumberErrorMessage(InvalidCustomsFormNumberInput $e): string
    {
        $code = in_array($e->getErrorCode(), self::CUSTOMS_FORM_NUMBER_ERROR_CODES, true)
            ? $e->getErrorCode()
            : 'generic';

        return __("examination.errors.customs_form_numbers.{$code}");
    }
}
