<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class InactiveUserAccessTest extends TestCase
{
    use DatabaseMigrations;

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'password123']);

        $this->post(route('auth.login.store'), [
            'username' => $user->username,
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertGuest();
    }

    public function test_deactivated_authenticated_user_is_logged_out_on_next_protected_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->is_active = false;
        $user->save();

        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('auth.inactive'));

        $this->assertGuest();
    }
}
