<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\UserAccountInvariantViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class UserAccountService
{
    /**
     * @param  array{name: string, username: string, email: ?string, password: string}  $attributes
     */
    public function register(array $attributes, string $preferredLocale = 'ms'): User
    {
        $user = new User;
        $user->name = $attributes['name'];
        $user->username = $attributes['username'];
        $user->email = $attributes['email'];
        $user->password = $attributes['password'];
        $user->role = UserRole::Agent;
        $user->is_active = true;
        $user->preferred_locale = $preferredLocale;
        $user->save();

        return $user;
    }

    /**
     * @param  array{name: string, username: string, email: ?string, role: UserRole, password: string, is_active: bool}  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        $this->assertAdminActor($actor);

        $user = new User;
        $user->name = $attributes['name'];
        $user->username = $attributes['username'];
        $user->email = $attributes['email'];
        $user->password = $attributes['password'];
        $user->role = $attributes['role'];
        $user->is_active = $attributes['is_active'];
        $user->save();

        Log::info('Admin created user account', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $user->id,
            'action' => 'user_created',
            'role' => $user->role->value,
            'is_active' => $user->is_active,
        ]);

        return $user;
    }

    /**
     * @param  array{name: string, username: string, email: ?string, role: UserRole, is_active: bool}  $attributes
     */
    public function update(User $actor, User $target, array $attributes): User
    {
        $this->assertAdminActor($actor);

        return DB::transaction(function () use ($actor, $target, $attributes): User {
            $this->lockActiveAdmins();
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $oldRole = $target->role;
            $oldActive = $target->is_active;

            $this->assertAllowedSecurityTransition($actor, $target, $attributes['role'], $attributes['is_active']);

            if ($oldRole !== $attributes['role'] || $oldActive !== $attributes['is_active']) {
                $this->assertActiveAdminRemains($target, $attributes['role'], $attributes['is_active']);
            }

            $target->name = $attributes['name'];
            $target->username = $attributes['username'];
            $target->email = $attributes['email'];
            $target->role = $attributes['role'];
            $target->is_active = $attributes['is_active'];
            $target->save();

            if ($oldRole !== $target->role) {
                Log::info('Admin changed user role', [
                    'actor_user_id' => $actor->id,
                    'target_user_id' => $target->id,
                    'action' => 'role_changed',
                    'old_role' => $oldRole->value,
                    'new_role' => $target->role->value,
                ]);
            }

            if ($oldActive !== $target->is_active) {
                Log::info('Admin changed user activation', [
                    'actor_user_id' => $actor->id,
                    'target_user_id' => $target->id,
                    'action' => $target->is_active ? 'activated' : 'deactivated',
                    'old_is_active' => $oldActive,
                    'new_is_active' => $target->is_active,
                ]);
            }

            return $target;
        });
    }

    public function changePassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->setRememberToken(str()->random(60));
        $user->save();
        $this->deleteOtherSessions($user);
    }

    public function resetPassword(User $actor, User $target, string $password): void
    {
        $this->assertAdminActor($actor);

        if ($actor->is($target)) {
            throw new UserAccountInvariantViolation('admin_self_reset');
        }

        $target->password = $password;
        $target->setRememberToken(str()->random(60));
        $target->save();
        $this->deleteAllSessions($target);

        Log::info('Admin reset user password', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'password_reset',
        ]);
    }

    private function assertAllowedSecurityTransition(User $actor, User $target, UserRole $role, bool $isActive): void
    {
        if (! $actor->is($target) || $actor->role !== UserRole::Admin) {
            return;
        }

        if ($role !== UserRole::Admin) {
            throw new UserAccountInvariantViolation('self_demotion');
        }

        if (! $isActive) {
            throw new UserAccountInvariantViolation('self_deactivation');
        }
    }

    private function assertAdminActor(User $actor): void
    {
        $authoritativeActor = User::query()->find($actor->id);

        if (! $authoritativeActor || $authoritativeActor->role !== UserRole::Admin || ! $authoritativeActor->is_active) {
            throw new UserAccountInvariantViolation('admin_required');
        }
    }

    private function lockActiveAdmins(): void
    {
        User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertActiveAdminRemains(User $target, UserRole $role, bool $isActive): void
    {
        $activeAdminCount = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->when($target->role === UserRole::Admin && $target->is_active, function ($query) use ($target): void {
                $query->where('id', '!=', $target->id);
            })
            ->count();

        if ($role === UserRole::Admin && $isActive) {
            $activeAdminCount++;
        }

        if ($activeAdminCount < 1) {
            throw new UserAccountInvariantViolation('last_active_admin');
        }
    }

    private function deleteOtherSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    private function deleteAllSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
