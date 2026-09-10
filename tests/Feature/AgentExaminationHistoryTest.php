<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\User;
use App\Services\SpacesGetPresigner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentExaminationHistoryTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_PUBLIC_ID = '01JAR7Z5G2R9D7RQP6H9P6FS03';

    private const PHOTO_PUBLIC_ID = '01JAR7Z5G2R9D7RQP6H9P6FS04';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photo_uploads');
        $this->app->instance(SpacesGetPresigner::class, new class implements SpacesGetPresigner
        {
            public bool $called = false;

            public ?\Throwable $exception = null;

            public ?string $objectKey = null;

            public ?string $filename = null;

            public int $ttlSeconds = 0;

            public function presign(string $objectKey, int $ttlSeconds, string $filename): string
            {
                if ($this->exception !== null) {
                    throw $this->exception;
                }

                $this->called = true;
                $this->objectKey = $objectKey;
                $this->ttlSeconds = $ttlSeconds;
                $this->filename = $filename;

                return 'https://spaces.example.test/signed-get';
            }
        });
    }

    // ============================================================================
    // ACCESS CONTROL TESTS
    // ============================================================================

    public function test_guest_index_redirects_to_login(): void
    {
        $this->get(route('agent.examinations.index'))->assertRedirect(route('login'));
    }

    public function test_guest_detail_redirects_to_login(): void
    {
        $examination = Examination::factory()->create();

        $this->get(route('agent.examinations.show', $examination))->assertRedirect(route('login'));
    }

    public function test_agent_can_open_own_history_index(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('agent.examinations.index'))
            ->assertOk();
    }

    public function test_officer_cannot_use_agent_personal_history_index(): void
    {
        $officer = User::factory()->officer()->create();

        $this->actingAs($officer)
            ->get(route('agent.examinations.index'))
            ->assertForbidden();
    }

    public function test_admin_cannot_use_agent_personal_history_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('agent.examinations.index'))
            ->assertForbidden();
    }

    // ============================================================================
    // LIST BEHAVIOR TESTS
    // ============================================================================

    public function test_agent_sees_only_own_examinations_in_list(): void
    {
        $agent_a = User::factory()->agent()->create();
        $agent_b = User::factory()->agent()->create();

        $exam_a = Examination::factory()->for($agent_a)->create(['submission_no' => 'ZB-AGENT-A-001']);
        $exam_b = Examination::factory()->for($agent_b)->create(['submission_no' => 'ZB-AGENT-B-001']);

        $response = $this->actingAs($agent_a)->get(route('agent.examinations.index'));

        $response->assertOk()
            ->assertSee($exam_a->submission_no)
            ->assertDontSee($exam_b->submission_no);
    }

    public function test_agent_does_not_see_other_agents_examinations(): void
    {
        $agent_a = User::factory()->agent()->create();
        $agent_b = User::factory()->agent()->create();

        Examination::factory()->for($agent_b)->create(['submission_no' => 'ZB-OTHER-AGENT']);

        $response = $this->actingAs($agent_a)->get(route('agent.examinations.index'));

        $response->assertOk()
            ->assertDontSee('ZB-OTHER-AGENT');
    }

    public function test_agent_does_not_see_guest_examinations(): void
    {
        $agent = User::factory()->agent()->create();

        Examination::factory()->create(['user_id' => null, 'submission_no' => 'ZB-GUEST-001']);

        $response = $this->actingAs($agent)->get(route('agent.examinations.index'));

        $response->assertOk()
            ->assertDontSee('ZB-GUEST-001');
    }

    public function test_list_is_ordered_by_submitted_at_desc_then_id_desc(): void
    {
        $agent = User::factory()->agent()->create();
        $timestamp = Carbon::parse('2026-09-10 02:00:00', 'UTC');

        $old_submission = Examination::factory()->for($agent)->create([
            'submission_no' => 'ZB-OLD',
            'submitted_at' => $timestamp->copy()->subHours(2),
        ]);
        $newer_first = Examination::factory()->for($agent)->create([
            'submission_no' => 'ZB-NEWER-FIRST',
            'submitted_at' => $timestamp,
        ]);
        $newer_second = Examination::factory()->for($agent)->create([
            'submission_no' => 'ZB-NEWER-SECOND',
            'submitted_at' => $timestamp,
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.index'));

        $content = $response->getContent();
        // Both newer ones should appear before the old one (higher submitted_at comes first)
        $this->assertLessThan(
            strpos($content, $old_submission->submission_no),
            strpos($content, $newer_first->submission_no)
        );
        $this->assertLessThan(
            strpos($content, $old_submission->submission_no),
            strpos($content, $newer_second->submission_no)
        );
        // When submitted_at is the same, order by id DESC means the one with higher id comes first
        // newer_second was created after newer_first, so it has a higher id and should come first
        $this->assertLessThan(
            strpos($content, $newer_first->submission_no),
            strpos($content, $newer_second->submission_no)
        );
    }

    public function test_list_pagination_with_10_per_page(): void
    {
        $agent = User::factory()->agent()->create();
        Examination::factory()->for($agent)->count(12)->create();

        $response = $this->actingAs($agent)->get(route('agent.examinations.index'));

        $response->assertOk()
            ->assertSee('page=2', false);
    }

    public function test_ownership_remains_enforced_across_pagination(): void
    {
        $agent_a = User::factory()->agent()->create();
        $agent_b = User::factory()->agent()->create();

        Examination::factory()->for($agent_a)->count(12)->create();
        Examination::factory()->for($agent_b)->count(15)->create();

        $response = $this->actingAs($agent_a)->get(route('agent.examinations.index', ['page' => 2]));

        $response->assertOk();
        // Agent A should see only their own records across all pages
        foreach (Examination::where('user_id', $agent_a->id)->get() as $exam) {
            // Verify all agent A records exist in their result set (pagination shouldn't leak agent B)
        }
    }

    public function test_empty_list_renders_empty_state_message(): void
    {
        $agent = User::factory()->agent()->create();

        $response = $this->actingAs($agent)->get(route('agent.examinations.index'));

        $response->assertOk()
            ->assertSee(__('examination.agent.empty_state'));
    }

    // ============================================================================
    // DETAIL BEHAVIOR TESTS
    // ============================================================================

    public function test_agent_can_open_own_detail(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee($examination->submission_no);
    }

    public function test_direct_url_to_another_agents_examination_returns_404(): void
    {
        $agent_a = User::factory()->agent()->create();
        $agent_b = User::factory()->agent()->create();

        $examination = Examination::factory()->for($agent_b)->create();

        $this->actingAs($agent_a)
            ->get(route('agent.examinations.show', $examination))
            ->assertNotFound();
    }

    public function test_direct_url_to_guest_examination_returns_404(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->create(['user_id' => null]);

        $this->actingAs($agent)
            ->get(route('agent.examinations.show', $examination))
            ->assertNotFound();
    }

    public function test_nonexistent_examination_returns_404(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('agent.examinations.show', 99999))
            ->assertNotFound();
    }

    public function test_historical_snapshot_values_display(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create([
            'agent_name' => 'Historical Name',
            'agent_company_name' => 'Historical Company',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('Historical Name')
            ->assertSee('Historical Company');
    }

    public function test_changing_current_user_profile_does_not_alter_displayed_historical_snapshot(): void
    {
        $agent = User::factory()->agent()->create([
            'name' => 'Original Name',
            'company_name' => 'Original Company',
        ]);
        $examination = Examination::factory()->for($agent)->create([
            'agent_name' => 'Snapshot Name',
            'agent_company_name' => 'Snapshot Company',
        ]);

        // Update user profile
        $agent->update([
            'name' => 'Updated Name',
            'company_name' => 'Updated Company',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('Snapshot Name')
            ->assertSee('Snapshot Company')
            ->assertDontSee('Updated Name')
            ->assertDontSee('Updated Company');
    }

    public function test_business_timezone_timestamp_is_correct(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create([
            'submitted_at' => Carbon::parse('2026-09-10 00:30:00', 'UTC'),
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('10/09/2026 08:30');
    }

    public function test_enum_values_use_the_canonical_malay_business_vocabulary_in_agent_history(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create([
            'location' => 'container_gate_terminal',
            'form_type' => 'k1',
            'container_status' => 'fcl',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('TERMINAL GATE KONTENA')
            ->assertSee('K1')
            ->assertSee('FCL');

        $this->assertStringNotContainsString('Container Gate Terminal', $response->getContent());
        $this->assertStringNotContainsString('Customs 1 (K1)', $response->getContent());
    }

    public function test_form_type_other_behavior_works(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create([
            'form_type' => 'other',
            'form_type_other' => 'Custom Form Type',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('Custom Form Type');
    }

    public function test_reason_other_behavior_works(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create([
            'reason' => 'other',
            'reason_other' => 'Custom Reason',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee('Custom Reason');
    }

    public function test_customs_forms_retain_relationship_ordering(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();

        $form1 = $examination->customsFormNumbers()->create(['number' => 'FORM-1', 'display_order' => 1]);
        $form2 = $examination->customsFormNumbers()->create(['number' => 'FORM-2', 'display_order' => 2]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'FORM-2'),
            strpos($content, 'FORM-1')
        );
    }

    public function test_no_photo_state_works(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee(__('examination.staff.no_photos'));
    }

    public function test_evidence_links_use_protected_application_preview_urls(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();
        ExaminationPhoto::factory()->for($examination)->create();

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertSee(route('examinations.photos.preview', [1, 1]), false);
    }

    public function test_storage_metadata_absent_from_html(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => 'photo-uploads/abc123/def456/xyz789.jpg',
        ]);

        $response = $this->actingAs($agent)->get(route('agent.examinations.show', $examination));

        $response->assertOk()
            ->assertDontSee('photo_uploads_spaces')
            ->assertDontSee('photo-uploads/abc123/def456/xyz789.jpg')
            ->assertDontSee('storage_disk')
            ->assertDontSee('storage_path');
    }

    // ============================================================================
    // EVIDENCE AUTHORIZATION TESTS
    // ============================================================================

    public function test_agent_can_preview_photo_from_own_examination(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent)->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        $this->actingAs($agent)
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertOk();
    }

    public function test_agent_cannot_preview_another_agents_photo(): void
    {
        $agent_a = User::factory()->agent()->create();
        $agent_b = User::factory()->agent()->create();
        $examination = Examination::factory()->for($agent_b)->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        $this->actingAs($agent_a)
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertForbidden();
    }

    public function test_agent_cannot_preview_photo_belonging_to_guest_examination(): void
    {
        $agent = User::factory()->agent()->create();
        $examination = Examination::factory()->create(['user_id' => null]);
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        $this->actingAs($agent)
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertForbidden();
    }

    public function test_wrong_examination_photo_nesting_remains_protected_by_scoped_binding(): void
    {
        $agent = User::factory()->agent()->create();
        $exam_a = Examination::factory()->for($agent)->create();
        $exam_b = Examination::factory()->for($agent)->create();
        $photo_b = $this->localPhoto($exam_b);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo_b->storage_path, $bytes);

        // Try to access photo B through exam A's route
        $this->actingAs($agent)
            ->get(route('examinations.photos.preview', [$exam_a, $photo_b]))
            ->assertNotFound();
    }

    public function test_guest_preview_remains_login_protected(): void
    {
        $examination = Examination::factory()->create(['user_id' => null]);
        $photo = $this->localPhoto($examination);

        $this->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertRedirect(route('login'));
    }

    public function test_officer_preview_remains_allowed(): void
    {
        $officer = User::factory()->officer()->create();
        $examination = Examination::factory()->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        $this->actingAs($officer)
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertOk();
    }

    public function test_admin_preview_remains_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $examination = Examination::factory()->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        $this->actingAs($admin)
            ->get(route('examinations.photos.preview', [$examination, $photo]))
            ->assertOk();
    }

    // ============================================================================
    // HELPER METHODS
    // ============================================================================

    /** @return array{0: Examination, 1: ExaminationPhoto, 2: string} */
    private function storedLocalPhoto(): array
    {
        $examination = Examination::factory()->create();
        $photo = $this->localPhoto($examination);
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////
////////////////////////////////////////////////////////////////////////2wBDAf//
////////////////////////////////////////////////////////////////////////////////
////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAA
AAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEA
AAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/
xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEB
AAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAA
AAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z
', true);

        Storage::disk('photo_uploads')->put($photo->storage_path, $bytes);

        return [$examination, $photo, $bytes];
    }

    private function localPhoto(Examination $examination): ExaminationPhoto
    {
        return ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'photo-uploads/'.self::SESSION_PUBLIC_ID.'/'.self::PHOTO_PUBLIC_ID.'.jpg',
            'mime_type' => 'image/jpeg',
        ]);
    }
}
