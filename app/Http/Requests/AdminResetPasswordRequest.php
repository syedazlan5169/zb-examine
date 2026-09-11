<?php

namespace App\Http\Requests;

use App\Support\UserAccountRules;
use Illuminate\Foundation\Http\FormRequest;

class AdminResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('resetPassword', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', UserAccountRules::password(), 'confirmed'],
        ];
    }
}
