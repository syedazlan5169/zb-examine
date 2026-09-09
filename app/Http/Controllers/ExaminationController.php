<?php

namespace App\Http\Controllers;

use App\Data\ExaminationSubmissionData;
use App\Data\PhotoUploadSessionCredentials;
use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Exceptions\SubmissionNumberSequenceExhausted;
use App\Http\Requests\ExaminationSubmissionRequest;
use App\Services\ExaminationSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ExaminationController extends Controller
{
    /** Machine-readable normalizer codes with a dedicated translation; anything else uses a generic message. */
    private const CUSTOMS_FORM_NUMBER_ERROR_CODES = [
        'empty_input',
        'empty_token',
        'value_too_long',
        'duplicate_number',
    ];

    /** Machine-readable photo-session codes with a dedicated translation (lang/{en,ms}/photo_upload.php). */
    private const PHOTO_ERROR_CODES = [
        'invalid_session',
        'session_expired',
        'session_finalized',
        'photo_count_invalid',
        'unverified_photo_pending',
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
        $photoCredentials = PhotoUploadSessionCredentials::fromRequest($request);

        try {
            $examination = $service->submit($data, $photoCredentials, auth()->user());
        } catch (InvalidCustomsFormNumberInput $e) {
            return $this->redirectBackWithInput($request)->withErrors([
                'customs_form_numbers' => $this->customsFormNumberErrorMessage($e),
            ]);
        } catch (SubmissionNumberSequenceExhausted $e) {
            Log::warning('Submission number sequence exhausted', ['business_date' => $e->getBusinessDate()]);

            return $this->redirectBackWithInput($request)->with('submission_error', __('examination.errors.sequence_exhausted'));
        } catch (PhotoUploadSessionInvalid|PhotoUploadInvalid $e) {
            return $this->redirectBackWithInput($request)->with('submission_error', $this->photoErrorMessage($e));
        } catch (Throwable $e) {
            report($e);

            return $this->redirectBackWithInput($request)->with('submission_error', __('examination.errors.generic_failure'));
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

    /**
     * Every manual redirect-with-input path must exclude the raw photo-upload
     * bearer token: bootstrap/app.php's dontFlash() only covers the automatic
     * ValidationException path, not these manual back()->withInput() calls.
     */
    private function redirectBackWithInput(Request $request): RedirectResponse
    {
        return back()->withInput($request->except('photo_upload_token'));
    }

    private function customsFormNumberErrorMessage(InvalidCustomsFormNumberInput $e): string
    {
        $code = in_array($e->getErrorCode(), self::CUSTOMS_FORM_NUMBER_ERROR_CODES, true)
            ? $e->getErrorCode()
            : 'generic';

        return __("examination.errors.customs_form_numbers.{$code}");
    }

    private function photoErrorMessage(PhotoUploadSessionInvalid|PhotoUploadInvalid $e): string
    {
        $code = in_array($e->getErrorCode(), self::PHOTO_ERROR_CODES, true)
            ? $e->getErrorCode()
            : 'generic_failure';

        return $code === 'generic_failure'
            ? __('examination.errors.generic_failure')
            : __("photo_upload.errors.{$code}");
    }
}
