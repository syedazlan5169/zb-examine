<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_can_register_an_active_agent_and_is_authenticated(): void
    {
        $this->post(route('auth.register.store'), [
            'name' => 'New Agent',
            'username' => '  New.Agent ',
            'email' => null,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('examinations.create'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'username' => 'new.agent',
            'role' => UserRole::Agent->value,
            'is_active' => true,
            'email' => null,
        ]);
    }

    public function test_registration_rejects_security_fields(): void
    {
        $this->from(route('register'))->post(route('auth.register.store'), [
            'name' => 'Escalating User',
            'username' => 'escalating-user',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Admin->value,
            'is_active' => false,
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors(['role', 'is_active']);

        $this->assertDatabaseMissing('users', ['username' => 'escalating-user']);
    }

    public function test_registration_enforces_username_email_and_password_rules(): void
    {
        User::factory()->create(['username' => 'existing-user', 'email' => 'existing@example.com']);

        $this->from(route('register'))->post(route('auth.register.store'), [
            'name' => 'Invalid User',
            'username' => ' EXISTING-USER ',
            'email' => 'existing@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors(['username', 'email', 'password']);
    }
}
