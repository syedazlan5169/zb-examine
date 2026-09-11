<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use DatabaseMigrations;

    public function test_all_roles_can_change_their_own_password(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->state(['role' => $role])->create(['password' => 'oldpass123']);

            $this->actingAs($user)->patch(route('profile.password.update'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])->assertRedirect(route('profile.password.edit'));

            $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
        }
    }

    public function test_wrong_current_password_and_confirmation_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'oldpass123']);

        $this->actingAs($user)->patch(route('profile.password.update'), [
            'current_password' => 'wrongpass123',
            'password' => 'newpass123',
            'password_confirmation' => 'different123',
        ])->assertSessionHasErrors(['current_password', 'password']);
    }

    public function test_admin_can_reset_another_password_but_not_their_own(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'adminpass123']);
        $target = User::factory()->officer()->create(['password' => 'oldpass123']);

        $this->actingAs($admin)->patch(route('admin.users.password.update', $target), [
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertRedirect(route('admin.users.edit', $target));

        $this->assertTrue(Hash::check('newpass123', $target->fresh()->password));

        $this->actingAs($admin)->patch(route('admin.users.password.update', $admin), [
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertForbidden();
    }

    public function test_own_password_change_regenerates_session_and_removes_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => 'oldpass123', 'remember_token' => 'old-token']);
        $otherUser = User::factory()->create();
        $oldToken = $user->remember_token;

        $this->actingAs($user);
        $currentSessionId = $this->app['session']->getId();
        DB::table('sessions')->insert([
            ['id' => $currentSessionId, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'user-a-other-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'user-a-other-2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'user-b-session', 'user_id' => $otherUser->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $response = $this->patch(route('profile.password.update'), [
            'current_password' => 'oldpass123',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);

        $newSessionId = $this->app['session']->getId();
        $response->assertRedirect(route('profile.password.edit'));
        $this->assertNotSame($currentSessionId, $newSessionId);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('sessions', ['id' => 'user-b-session', 'user_id' => $otherUser->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-a-other-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-a-other-2']);
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_admin_reset_removes_all_target_sessions_and_rotates_token(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $target = User::factory()->officer()->create(['password' => 'oldpass123', 'remember_token' => 'old-target-token']);
        $otherUser = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'target-session-1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'target-session-2', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-session', 'user_id' => $otherUser->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($admin)->patch(route('admin.users.password.update', $target), [
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertRedirect(route('admin.users.edit', $target));

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $otherUser->id]);
        $this->assertNotSame('old-target-token', $target->fresh()->remember_token);
        $this->assertTrue(Hash::check('newpass123', $target->fresh()->password));
    }
}
