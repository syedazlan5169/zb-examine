<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Models\User;
use App\Policies\ExaminationPhotoPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthenticationAndEvidenceAuthorizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_login_page_renders_in_the_active_locale(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee(__('auth.login'))
            ->assertSee(__('auth.username'))
            ->assertSee(__('auth.password'))
            ->assertSee('name="username"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee(__('auth.email'));
    }

    public function test_login_page_renders_the_english_username_label(): void
    {
        app()->setLocale('en');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Username')
            ->assertSee('Password')
            ->assertSee('Sign in')
            ->assertDontSee('Email');
    }

    public function test_agent_login_uses_the_new_submission_home(): void
    {
        $user = User::factory()->agent()->create([
            'username' => 'agent-one',
            'email' => null,
            'password' => 'correct-password',
        ]);
        $sessionId = $this->app['session']->getId();

        $response = $this->post(route('auth.login.store'), [
            'username' => 'agent-one',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('examinations.create'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, $this->app['session']->getId());
    }

    public function test_officer_login_uses_the_examinations_home(): void
    {
        $user = User::factory()->officer()->create([
            'username' => 'officer-one',
            'email' => null,
            'password' => 'correct-password',
        ]);
        $sessionId = $this->app['session']->getId();

        $response = $this->post(route('auth.login.store'), [
            'username' => 'officer-one',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('examinations.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, $this->app['session']->getId());
    }

    public function test_authenticated_root_uses_each_role_home(): void
    {
        $this->actingAs(User::factory()->agent()->create())
            ->get(route('home'))
            ->assertRedirect(route('examinations.create'));

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('home'))
            ->assertRedirect(route('examinations.index'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('home'))
            ->assertRedirect(route('examinations.index'));
    }

    public function test_literal_form_and_success_routes_are_not_captured_by_examination_binding(): void
    {
        $this->get(route('examinations.create'))->assertOk();
        $this->get(route('examinations.success'))->assertRedirect(route('examinations.create'));
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $this->get(route('login'))
            ->assertRedirect(route('examinations.index'));
    }

    public function test_authenticated_users_cannot_submit_login_again(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $this->post(route('auth.login.store'), [
            'username' => 'another-user',
            'password' => 'another-password',
        ])->assertRedirect(route('examinations.index'));
    }

    public function test_username_is_normalized_before_authentication(): void
    {
        $user = User::factory()->officer()->create([
            'username' => 'officer.one',
            'email' => null,
            'password' => 'correct-password',
        ]);

        $this->post(route('auth.login.store'), [
            'username' => '  Officer.One  ',
            'password' => 'correct-password',
        ])->assertRedirect(route('examinations.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_usernames_are_stored_in_canonical_form(): void
    {
        $user = User::factory()->create([
            'username' => '  Officer.One  ',
        ]);

        $this->assertSame('officer.one', $user->username);
    }

    public function test_wrong_password_fails_with_a_generic_username_error(): void
    {
        User::factory()->officer()->create([
            'username' => 'officer-one',
            'password' => 'correct-password',
        ]);

        $response = $this->from(route('login'))->post(route('auth.login.store'), [
            'username' => 'officer-one',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => __('auth.failed')])
            ->assertSessionMissing('password');
        $this->assertGuest();
    }

    public function test_unknown_username_fails_with_the_same_generic_error(): void
    {
        $response = $this->from(route('login'))->post(route('auth.login.store'), [
            'username' => 'unknown-user',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => __('auth.failed')])
            ->assertSessionMissing('password');
        $this->assertGuest();
    }

    public function test_whitespace_only_username_fails_validation(): void
    {
        $this->from(route('login'))->post(route('auth.login.store'), [
            'username' => '   ',
            'password' => 'password',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username']);

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_clears_authentication(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $response = $this->post(route('auth.logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_guests_cannot_post_to_logout(): void
    {
        $this->post(route('auth.logout'))
            ->assertRedirect(route('login'));
    }

    public function test_repeated_invalid_login_attempts_share_a_normalized_username_and_ip_bucket(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from(route('login'))->post(route('auth.login.store'), [
                'username' => $attempt % 2 === 0 ? ' STAFF.One ' : 'staff.one',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
        }

        $this->from(route('login'))->post(route('auth.login.store'), [
            'username' => 'STAFF.ONE',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();

        $this->from(route('login'))->post(route('auth.login.store'), [
            'username' => 'different-user',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));
    }

    public function test_same_username_from_a_different_ip_uses_a_separate_throttle_bucket(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from(route('login'))->post(route('auth.login.store'), [
                'username' => 'staff-one',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->from(route('login'))
            ->post(route('auth.login.store'), [
                'username' => 'staff-one',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
    }

    public function test_public_examination_form_remains_guest_accessible(): void
    {
        $this->get(route('examinations.create'))->assertOk();
        $this->assertGuest();
    }

    public function test_finalized_evidence_policy_allows_only_officers_and_admins(): void
    {
        $photo = ExaminationPhoto::factory()->create();

        foreach ([UserRole::Officer, UserRole::Admin] as $role) {
            $this->assertTrue(Gate::forUser(User::factory()->state(['role' => $role])->make())->allows('view', $photo));
        }

        foreach ([UserRole::Agent, null] as $role) {
            $user = $role === null ? null : User::factory()->state(['role' => $role])->make();

            $this->assertFalse(Gate::forUser($user)->allows('view', $photo));
        }
    }

    public function test_policy_targets_finalized_examination_photos_not_temporary_uploads(): void
    {
        $pendingUpload = PhotoUpload::factory()->make();

        $this->assertInstanceOf(ExaminationPhotoPolicy::class, Gate::getPolicyFor(ExaminationPhoto::class));
        $this->assertNull(Gate::getPolicyFor(PhotoUpload::class));
        $this->assertNull(Gate::getPolicyFor(PhotoUploadSession::class));
        $this->assertNotInstanceOf(ExaminationPhoto::class, $pendingUpload);
    }
}
