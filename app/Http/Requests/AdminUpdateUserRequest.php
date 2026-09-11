<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserAccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                ...UserAccountRules::username(),
                Rule::unique('users', 'username')->ignore($user),
            ],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
