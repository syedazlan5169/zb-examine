<?php

namespace App\Data;

use Illuminate\Http\Request;

/**
 * Raw bearer-token carrier, deliberately separate from ExaminationSubmissionData
 * so the token can never be mass-assigned/persisted alongside domain fields.
 */
final readonly class PhotoUploadSessionCredentials
{
    public function __construct(
        public string $publicId,
        public string $token,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            publicId: (string) $request->input('photo_upload_session_public_id'),
            token: (string) $request->input('photo_upload_token'),
        );
    }
}
