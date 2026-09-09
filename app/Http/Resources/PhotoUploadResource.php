<?php

namespace App\Http\Resources;

use App\Models\PhotoUpload;
use App\Services\PhotoUploadObjectPath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allowlist shape: never exposes the internal id, photo_upload_session_id,
 * storage_disk, or storage_path.
 *
 * @mixin PhotoUpload
 */
class PhotoUploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verified = $this->verified_at !== null;

        $mode = PhotoUploadObjectPath::classifyStorage($this->storage_disk, $this->storage_path);

        return [
            'public_id' => $this->public_id,
            'display_order' => $this->display_order,
            'upload_mode' => $mode,
            'verified' => $verified,
            'mime_type' => $verified ? $this->mime_type : null,
            'file_size' => $verified ? $this->file_size : null,
            'width' => $verified ? $this->width : null,
            'height' => $verified ? $this->height : null,
        ];
    }
}
