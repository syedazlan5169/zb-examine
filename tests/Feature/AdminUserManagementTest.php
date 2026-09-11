<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\UserAccountInvariantViolation;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use DatabaseMigrations;

    public function test_only_admin_can_access_user_management(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        foreach ([UserRole::Agent, UserRole::Officer] as $role) {
            $this->actingAs(User::factory()->state(['role' => $role])->create())
                ->get(route('admin.users.index'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_create_each_role_and_an_inactive_account(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (UserRole::cases() as $index => $role) {
            $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => $role->value,
                'username' => $role->value.'-'.$index,
                'email' => null,
                'role' => $role->value,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => $role === UserRole::Officer ? '0' : '1',
            ])->assertRedirect(route('admin.users.index'));
        }

        $this->assertDatabaseHas('users', ['username' => 'officer-1', 'is_active' => false]);
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([UserRole::Agent->value, UserRole::Officer->value] as $role) {
            $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => $role,
                'is_active' => true,
            ])->assertSessionHasErrors('account');
        }

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => UserRole::Admin->value,
            'is_active' => false,
        ])->assertSessionHasErrors('account');
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $actor = User::factory()->admin()->make(['id' => $admin->id + 1]);

        $this->expectException(UserAccountInvariantViolation::class);

        app(UserAccountService::class)->update($actor, $admin, [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => UserRole::Agent,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_another_user_role_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->agent()->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'role' => UserRole::Officer->value,
            'is_active' => false,
        ])->assertRedirect(route('admin.users.edit', $target));

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::Officer->value,
            'is_active' => false,
        ]);
    }

    public function test_http_last_active_admin_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([UserRole::Agent->value, UserRole::Officer->value] as $role) {
            $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => $role,
                'is_active' => true,
            ])->assertSessionHasErrors('account');
        }

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => UserRole::Admin->value,
            'is_active' => false,
        ])->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => UserRole::Admin->value,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_change_another_admin_when_another_active_admin_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'role' => UserRole::Officer->value,
            'is_active' => false,
        ])->assertRedirect(route('admin.users.edit', $target));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => UserRole::Officer->value, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => UserRole::Admin->value, 'is_active' => true]);
    }

    public function test_admin_mutations_emit_safe_structured_logs(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->agent()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Created User',
            'username' => 'created-user',
            'email' => null,
            'role' => UserRole::Officer->value,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'is_active' => '1',
        ]);
        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'role' => UserRole::Officer->value,
            'is_active' => false,
        ]);
        $this->actingAs($admin)->patch(route('admin.users.password.update', $target), [
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin): bool {
            return in_array($message, ['Admin created user account', 'Admin changed user role', 'Admin changed user activation', 'Admin reset user password'], true)
                && $context['actor_user_id'] === $admin->id
                && isset($context['target_user_id'])
                && ! array_key_exists('password', $context)
                && ! array_key_exists('remember_token', $context)
                && ! array_key_exists('session_id', $context);
        })->atLeast()->once();
    }

    public function test_service_rejects_non_admin_or_inactive_admin_mutations(): void
    {
        $service = app(UserAccountService::class);
        $agent = User::factory()->agent()->create();
        $target = User::factory()->agent()->create();
        $attributes = [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'role' => UserRole::Officer,
            'is_active' => true,
        ];

        $this->expectException(UserAccountInvariantViolation::class);
        $service->update($agent, $target, $attributes);
    }

    public function test_role_labels_are_localized(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->agent()->create(['name' => 'Agent Label']);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertSee(__('users.roles.agent'))
            ->assertSee(__('users.roles.admin'));

        app()->setLocale('ms');
        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertSee('Ejen')
            ->assertSee('Pentadbir');
    }
}
