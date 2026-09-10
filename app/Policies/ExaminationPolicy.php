<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Examination;
use App\Models\User;

class ExaminationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Officer, UserRole::Admin], true);
    }

    public function view(User $user, Examination $examination): bool
    {
        return $examination->exists
            && in_array($user->role, [UserRole::Officer, UserRole::Admin], true);
    }

    public function viewOwn(User $user, Examination $examination): bool
    {
        return $examination->exists
            && $user->role === UserRole::Agent
            && $examination->user_id === $user->id;
    }
}
