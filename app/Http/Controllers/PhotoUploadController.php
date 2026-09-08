<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoUploadFileRequest;
use App\Http\Resources\PhotoUploadResource;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PhotoUploadController extends Controller
{
    public function __construct(private readonly PhotoUploadService $service) {}

    public function store(Request $request, string $sessionPublicId): JsonResponse
    {
        $upload = $this->service->allocate($sessionPublicId, $this->token($request));

        return response()->json([
            'public_id' => $upload->public_id,
            'display_order' => $upload->display_order,
        ], 201);
    }

    public function upload(StorePhotoUploadFileRequest $request, string $sessionPublicId, string $photoPublicId): JsonResponse
    {
        $this->service->upload($sessionPublicId, $this->token($request), $photoPublicId, $request->file('photo'));

        return response()->json(['status' => 'stored']);
    }

    public function complete(Request $request, string $sessionPublicId, string $photoPublicId): JsonResponse
    {
        $upload = $this->service->complete($sessionPublicId, $this->token($request), $photoPublicId);

        return response()->json(PhotoUploadResource::make($upload)->resolve());
    }

    public function destroy(Request $request, string $sessionPublicId, string $photoPublicId): Response
    {
        $this->service->remove($sessionPublicId, $this->token($request), $photoPublicId);

        return response()->noContent();
    }

    private function token(Request $request): string
    {
        return (string) $request->header('X-Photo-Upload-Token');
    }
}
