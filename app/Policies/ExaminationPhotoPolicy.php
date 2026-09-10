<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ExaminationPhoto;
use App\Models\User;

class ExaminationPhotoPolicy
{
    public function view(User $user, ExaminationPhoto $examinationPhoto): bool
    {
        return $examinationPhoto->exists
            && $examinationPhoto->examination_id !== null
            && (
                in_array($user->role, [UserRole::Officer, UserRole::Admin], true)
                ||
                (
                    $user->role === UserRole::Agent
                    && $examinationPhoto->examination->user_id === $user->id
                )
            );
    }
}
