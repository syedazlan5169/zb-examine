<?php

namespace App\Http\Requests;

use App\Support\UserAccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => UserAccountRules::normalizeUsername((string) $this->input('username', '')),
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                ...UserAccountRules::username(),
                Rule::unique('users', 'username'),
            ],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', UserAccountRules::password(), 'confirmed'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }
}
