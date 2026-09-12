<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_locale_switch_is_session_only(): void
    {
        $this->get(route('language.switch', 'en'))
            ->assertRedirect();

        $this->assertGuest();
        $this->assertSame('en', session('locale'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_authenticated_locale_switch_persists_and_applies_immediately(): void
    {
        $user = User::factory()->agent()->create(['preferred_locale' => 'ms']);

        $this->actingAs($user)
            ->get(route('language.switch', 'en'))
            ->assertRedirect();

        $this->assertSame('en', session('locale'));
        $this->assertSame('en', $user->fresh()->preferred_locale);

        $this->actingAs($user)
            ->get(route('examinations.create'))
            ->assertSee('Examination Submission')
            ->assertDontSee('Pendaftaran Pemeriksaan');
    }

    public function test_authenticated_user_preference_overrides_guest_session_locale(): void
    {
        $user = User::factory()->agent()->create(['preferred_locale' => 'ms']);

        $this->withSession(['locale' => 'en'])
            ->actingAs($user)
            ->get(route('examinations.create'))
            ->assertSee('Pendaftaran Pemeriksaan')
            ->assertDontSee('Examination Submission');
    }

    public function test_login_replaces_guest_english_session_locale_with_malay_user_preference(): void
    {
        $user = User::factory()->agent()->create([
            'username' => 'malay-agent',
            'password' => 'correct-password',
            'preferred_locale' => 'ms',
        ]);

        $this->get(route('language.switch', 'en'));
        $this->post(route('auth.login.store'), [
            'username' => $user->username,
            'password' => 'correct-password',
        ]);

        $this->get(route('examinations.create'))
            ->assertSee('Pendaftaran Pemeriksaan')
            ->assertDontSee('Examination Submission');
    }

    public function test_login_replaces_guest_malay_session_locale_with_english_user_preference(): void
    {
        $user = User::factory()->agent()->create([
            'username' => 'english-agent-two',
            'password' => 'correct-password',
            'preferred_locale' => 'en',
        ]);

        $this->get(route('language.switch', 'ms'));
        $this->post(route('auth.login.store'), [
            'username' => $user->username,
            'password' => 'correct-password',
        ]);

        $this->get(route('examinations.create'))
            ->assertSee('Examination Submission')
            ->assertDontSee('Pendaftaran Pemeriksaan');
    }

    public function test_authenticated_user_with_english_preference_survives_logout_and_login(): void
    {
        $user = User::factory()->agent()->create([
            'username' => 'locale-agent',
            'password' => 'correct-password',
            'preferred_locale' => 'en',
        ]);

        $this->actingAs($user)->post(route('auth.logout'));

        $this->post(route('auth.login.store'), [
            'username' => $user->username,
            'password' => 'correct-password',
        ]);

        $this->get(route('examinations.create'))
            ->assertSee('Examination Submission')
            ->assertDontSee('Pendaftaran Pemeriksaan');
    }

    public function test_invalid_legacy_preference_falls_back_to_application_locale(): void
    {
        $user = User::factory()->agent()->create(['preferred_locale' => 'fr']);

        $this->actingAs($user)
            ->get(route('examinations.create'))
            ->assertSee('Pendaftaran Pemeriksaan');
    }

    public function test_registration_initializes_preference_from_guest_locale(): void
    {
        $this->get(route('language.switch', 'en'));

        $this->post(route('auth.register.store'), [
            'name' => 'English Agent',
            'username' => 'english-agent',
            'email' => null,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('examinations.create'));

        $this->assertSame('en', User::where('username', 'english-agent')->value('preferred_locale'));
    }
}
