<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Examination;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_get_profile_redirects_to_login(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_guest_patch_profile_redirects_to_login(): void
    {
        $this->patch(route('profile.update'), [])->assertRedirect(route('login'));
    }

    public function test_agent_can_view_own_profile(): void
    {
        $user = User::factory()->agent()->create([
            'name' => 'Agent User',
            'phone' => '0123456789',
            'agent_code' => 'AG-100',
            'company_name' => 'Alpha Company',
            'station_code' => 'ST-100',
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('profile.title'))
            ->assertSee(__('examination.fields.agent_name'))
            ->assertSee('Agent User');
    }

    public function test_officer_can_view_and_update_general_profile(): void
    {
        $user = User::factory()->officer()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('profile.account'))
            ->assertSee(__('users.name'))
            ->assertDontSee(__('examination.fields.agent_code'));

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Updated Officer',
                'email' => 'officer.updated@example.com',
            ])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_admin_can_view_general_profile(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('profile.account'))
            ->assertDontSee(__('examination.fields.agent_code'));
    }

    public function test_officer_and_admin_cannot_update_agent_only_fields(): void
    {
        foreach ([UserRole::Officer, UserRole::Admin] as $role) {
            $user = User::factory()->state([
                'role' => $role,
                'phone' => 'original-phone',
                'agent_code' => 'original-code',
                'company_name' => 'Original Company',
                'station_code' => 'original-station',
            ])->create();

            $this->actingAs($user)->patch(route('profile.update'), [
                'name' => 'Updated Name',
                'email' => 'updated-'.$role->value.'@example.com',
                'phone' => 'tampered-phone',
                'agent_code' => 'tampered-code',
                'company_name' => 'Tampered Company',
                'station_code' => 'tampered-station',
            ])->assertRedirect(route('profile.edit'));

            $user->refresh();
            $this->assertSame('original-phone', $user->phone);
            $this->assertSame('original-code', $user->agent_code);
            $this->assertSame('Original Company', $user->company_name);
            $this->assertSame('original-station', $user->station_code);
        }
    }

    public function test_agent_can_update_profile_fields(): void
    {
        $user = User::factory()->agent()->create([
            'name' => 'Original Name',
            'phone' => '1111111111',
            'agent_code' => 'OLD-CODE',
            'company_name' => 'Old Company',
            'station_code' => 'OLD-STATION',
        ]);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Updated Name',
                'phone' => '0123456789',
                'agent_code' => 'NEW-CODE',
                'company_name' => 'New Company',
                'station_code' => 'NEW-STATION',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', __('profile.updated'));

        $user->refresh();

        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('0123456789', $user->phone);
        $this->assertSame('NEW-CODE', $user->agent_code);
        $this->assertSame('New Company', $user->company_name);
        $this->assertSame('NEW-STATION', $user->station_code);
    }

    public function test_invalid_profile_data_returns_validation_errors(): void
    {
        $user = User::factory()->agent()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => str_repeat('A', 256),
                'phone' => str_repeat('9', 31),
                'agent_code' => str_repeat('B', 51),
                'company_name' => str_repeat('C', 256),
                'station_code' => str_repeat('D', 51),
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors(['name', 'phone', 'agent_code', 'company_name', 'station_code']);
    }

    public function test_profile_update_ignores_protected_user_fields(): void
    {
        $originalPassword = 'correct-password';
        $user = User::factory()->agent()->create([
            'username' => 'agent-one',
            'email' => 'agent@example.com',
            'password' => $originalPassword,
            'role' => UserRole::Agent,
        ]);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'New Agent Name',
                'phone' => '0123456789',
                'agent_code' => 'PROFILE-CODE',
                'company_name' => 'Profile Company',
                'station_code' => 'PROFILE-STATION',
                'role' => 'admin',
                'username' => 'hacker',
                'email' => 'hacker@example.com',
                'password' => 'new-password',
            ]);

        $user->refresh();

        $this->assertSame('agent-one', $user->username);
        $this->assertSame('hacker@example.com', $user->email);
        $this->assertSame(UserRole::Agent, $user->role);
        $this->assertTrue(Hash::check('correct-password', $user->password));
        $this->assertNotSame('new-password', $user->password);
    }

    public function test_profile_page_renders_malay_default(): void
    {
        $this->actingAs(User::factory()->agent()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('profile.title'))
            ->assertSee(__('profile.agent_profile'))
            ->assertSee(__('profile.save'));
    }

    public function test_profile_page_renders_english_when_locale_is_english(): void
    {
        app()->setLocale('en');

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('profile.title'))
            ->assertSee(__('profile.agent_profile'))
            ->assertSee(__('profile.save'));
    }

    public function test_authenticated_agent_get_form_prefills_profile_values(): void
    {
        $user = User::factory()->agent()->create([
            'name' => 'Saved Agent Name',
            'phone' => '5555555555',
            'agent_code' => 'AG-SAVED',
            'company_name' => 'Saved Company',
            'station_code' => 'ST-SAVED',
        ]);

        $response = $this->actingAs($user)->get(route('examinations.create'));

        $response->assertOk();
        $response->assertSee('value="Saved Agent Name"', false);
        $response->assertSee('value="5555555555"', false);
        $response->assertSee('value="AG-SAVED"', false);
        $response->assertSee('value="Saved Company"', false);
        $response->assertSee('value="ST-SAVED"', false);
    }

    public function test_guest_form_remains_blank_manual_and_no_profile_defaults_are_used(): void
    {
        $agent = User::factory()->agent()->create([
            'name' => 'Saved Agent Name',
            'phone' => '5555555555',
            'agent_code' => 'AG-SAVED',
            'company_name' => 'Saved Company',
            'station_code' => 'ST-SAVED',
        ]);

        $response = $this->get(route('examinations.create'));

        $response->assertOk();
        $response->assertDontSee('value="Saved Agent Name"', false);
        $response->assertDontSee('value="Saved Company"', false);
        $response->assertDontSee('value="AG-SAVED"', false);
    }

    public function test_officer_and_admin_do_not_get_agent_autofill(): void
    {
        $profileUser = User::factory()->agent()->create([
            'name' => 'Saved Agent Name',
            'phone' => '5555555555',
            'agent_code' => 'AG-SAVED',
            'company_name' => 'Saved Company',
            'station_code' => 'ST-SAVED',
        ]);

        foreach ([User::factory()->officer()->create(), User::factory()->admin()->create()] as $user) {
            $response = $this->actingAs($user)->get(route('examinations.create'));

            $response->assertOk();
            $response->assertDontSee('value="Saved Agent Name"', false);
            $response->assertDontSee('value="Saved Company"', false);
            $response->assertDontSee('value="AG-SAVED"', false);
        }

        $this->assertNotNull($profileUser->fresh());
    }

    public function test_old_validation_input_overrides_profile_defaults(): void
    {
        $user = User::factory()->agent()->create([
            'name' => 'Saved Agent Name',
            'phone' => '5555555555',
            'agent_code' => 'AG-SAVED',
            'company_name' => 'Saved Company',
            'station_code' => 'ST-SAVED',
        ]);

        $payload = $this->validSubmissionPayload([
            'agent_name' => 'Typed Agent',
            'agent_phone' => '0112233445',
            'agent_code' => 'TYPED-CODE',
            'agent_company_name' => 'Typed Company',
            'agent_station_code' => 'TYPED-STATION',
        ]);

        $invalidPayload = array_merge($payload, ['customs_form_numbers' => ['DUP-1', 'DUP-1']]);

        $this->actingAs($user)
            ->from(route('examinations.create'))
            ->post('/examinations', $invalidPayload)
            ->assertSessionHasErrors(['customs_form_numbers.1']);

        $response = $this->actingAs($user)->get(route('examinations.create'));

        $response->assertOk();
        $response->assertSee('value="Typed Agent"', false);
        $response->assertDontSee('value="Saved Agent Name"', false);
    }

    public function test_agent_can_edit_prefilled_value_before_submission_and_it_persists(): void
    {
        $user = User::factory()->agent()->create([
            'company_name' => 'Profile Company',
        ]);

        $this->actingAs($user)
            ->post('/examinations', $this->validSubmissionPayload([
                'agent_name' => 'Edited Agent',
                'agent_phone' => '0123456789',
                'agent_code' => 'AG-EDITED',
                'agent_company_name' => 'Edited Company',
                'agent_station_code' => 'ST-EDITED',
            ]));

        $examination = Examination::firstOrFail();

        $this->assertSame($user->id, $examination->user_id);
        $this->assertSame('Edited Company', $examination->agent_company_name);
    }

    public function test_profile_changes_do_not_change_historical_examination_snapshot(): void
    {
        $user = User::factory()->agent()->create([
            'company_name' => 'Company A',
        ]);

        $this->actingAs($user)->post('/examinations', $this->validSubmissionPayload([
            'agent_name' => 'Agent Name',
            'agent_phone' => '0123456789',
            'agent_code' => 'AG-SNAPSHOT',
            'agent_company_name' => 'Company A',
            'agent_station_code' => 'ST-SNAPSHOT',
        ]));

        $user->update(['company_name' => 'Company B']);

        $this->assertSame('Company A', Examination::firstOrFail()->agent_company_name);
    }

    private function validSubmissionPayload(array $overrides = []): array
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        return array_merge([
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Penghantaran Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => 'container_gate_terminal',
            'form_type' => 'k1',
            'customs_form_numbers' => ['ABC/2026/123'],
            'container_status' => 'fcl',
            'reason' => null,
            'attending_officer_type' => 'customs',
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ], $overrides);
    }
}
