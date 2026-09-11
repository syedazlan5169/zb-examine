<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin && $user->isNot($target);
    }

    public function changeStatus(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin;
    }
}
