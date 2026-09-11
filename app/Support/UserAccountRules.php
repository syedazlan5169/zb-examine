<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rules\Password;

final class UserAccountRules
{
    public static function username(): array
    {
        return ['required', 'string', 'max:50'];
    }

    public static function password(): Password
    {
        return Password::min(8)
            ->max(72)
            ->letters()
            ->numbers();
    }

    public static function normalizeUsername(string $username): string
    {
        return User::normalizeUsername($username);
    }
}
