<?php

namespace App\Http\Requests;

use App\Support\UserAccountRules;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => UserAccountRules::normalizeUsername((string) $this->input('username', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'username' => UserAccountRules::username(),
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array{username: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'username' => $this->validated('username'),
            'password' => $this->validated('password'),
            'is_active' => true,
        ];
    }
}
