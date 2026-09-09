<?php

namespace App\Http\Controllers;

use App\Exceptions\FinalizedEvidenceDeliveryUnavailable;
use App\Exceptions\FinalizedEvidenceNotFound;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Services\FinalizedEvidenceAccessService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExaminationPhotoPreviewController extends Controller
{
    public function show(Examination $examination, ExaminationPhoto $photo, FinalizedEvidenceAccessService $service): StreamedResponse
    {
        Gate::authorize('view', $photo);

        try {
            $evidence = $service->open($photo);
        } catch (FinalizedEvidenceNotFound) {
            abort(404);
        } catch (FinalizedEvidenceDeliveryUnavailable) {
            abort(503);
        }

        $headers = [
            'Content-Type' => $evidence->mimeType,
            'Content-Disposition' => 'inline; filename="'.$evidence->filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($evidence->contentLength !== null) {
            $headers['Content-Length'] = (string) $evidence->contentLength;
        }

        return response()->stream(function () use ($evidence): void {
            try {
                fpassthru($evidence->stream);
            } finally {
                $evidence->close();
            }
        }, 200, $headers);
    }
}
