<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

final class RoleHomeResolver
{
    public function routeName(User $user): string
    {
        return $user->role === UserRole::Agent
            ? 'examinations.create'
            : 'examinations.index';
    }
}
