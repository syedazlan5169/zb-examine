<?php

namespace App\Http\Controllers;

use App\Http\Resources\PhotoUploadResource;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadSessionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoUploadSessionController extends Controller
{
    public function store(): JsonResponse
    {
        // Token generation lives only in the model's canonical issue() method.
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();

        return response()->json([
            'public_id' => $session->public_id,
            'token' => $token,
            'expires_at' => $session->expires_at->toIso8601String(),
            'photos' => [],
        ], 201);
    }

    public function show(Request $request, string $sessionPublicId, PhotoUploadSessionResolver $resolver): JsonResponse
    {
        $session = $resolver->resolve($sessionPublicId, (string) $request->header('X-Photo-Upload-Token'));

        $session->load('photoUploads');

        return response()->json([
            'public_id' => $session->public_id,
            'expires_at' => $session->expires_at->toIso8601String(),
            'finalized' => $session->examination_id !== null,
            'photos' => PhotoUploadResource::collection($session->photoUploads),
        ]);
    }
}
