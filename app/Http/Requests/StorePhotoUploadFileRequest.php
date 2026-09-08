<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePhotoUploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 2048 KB = the locked 2 MiB hard cap; authoritative check still
            // happens server-side against the actual stored object in complete().
            'photo' => ['required', 'file', 'mimes:jpeg', 'max:2048'],
        ];
    }
}
