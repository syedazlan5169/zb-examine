<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserAccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
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
            'username' => [...UserAccountRules::username(), Rule::unique('users', 'username')],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', UserAccountRules::password(), 'confirmed'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
