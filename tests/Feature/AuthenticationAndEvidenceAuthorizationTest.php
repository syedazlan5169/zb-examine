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
            ->assertSee(__('auth.email'))
            ->assertSee(__('auth.password'));
    }

    public function test_valid_credentials_authenticate_and_regenerate_the_session(): void
    {
        $user = User::factory()->officer()->create([
            'email' => 'officer@example.test',
            'password' => 'correct-password',
        ]);
        $sessionId = $this->app['session']->getId();

        $response = $this->post(route('auth.login.store'), [
            'email' => 'officer@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('examinations.create'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, $this->app['session']->getId());
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $this->get(route('login'))
            ->assertRedirect(route('examinations.create'));
    }

    public function test_authenticated_users_cannot_submit_login_again(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $this->post(route('auth.login.store'), [
            'email' => 'another@example.test',
            'password' => 'another-password',
        ])->assertRedirect(route('examinations.create'));
    }

    public function test_invalid_credentials_are_rejected_without_authentication(): void
    {
        User::factory()->officer()->create([
            'email' => 'officer@example.test',
            'password' => 'correct-password',
        ]);

        $response = $this->from(route('login'))->post(route('auth.login.store'), [
            'email' => 'officer@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_clears_authentication(): void
    {
        $this->actingAs(User::factory()->officer()->create());

        $response = $this->post(route('auth.logout'));

        $response->assertRedirect(route('examinations.create'));
        $this->assertGuest();
    }

    public function test_guests_cannot_post_to_logout(): void
    {
        $this->post(route('auth.logout'))
            ->assertRedirect(route('login'));
    }

    public function test_repeated_invalid_login_attempts_are_throttled_by_normalized_email_and_ip(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from(route('login'))->post(route('auth.login.store'), [
                'email' => $attempt % 2 === 0 ? 'STAFF@example.test' : 'staff@example.test',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
        }

        $this->from(route('login'))->post(route('auth.login.store'), [
            'email' => 'STAFF@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
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
