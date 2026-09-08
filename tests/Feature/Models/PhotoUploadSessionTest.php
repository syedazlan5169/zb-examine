<?php

namespace Tests\Feature\Models;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhotoUploadSessionTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase): repo convention for anything
    // touching the examinations table (see AGENTS/repo memory).
    use DatabaseMigrations;

    public function test_a_session_can_be_persisted(): void
    {
        $session = PhotoUploadSession::factory()->create();

        $this->assertDatabaseHas('photo_upload_sessions', ['id' => $session->id]);
    }

    public function test_public_id_is_automatically_generated(): void
    {
        ['session' => $session] = PhotoUploadSession::issue();

        $this->assertNotEmpty($session->public_id);
        $this->assertSame(26, strlen($session->public_id));
    }

    public function test_public_id_is_unique(): void
    {
        $first = PhotoUploadSession::factory()->create();

        $this->expectException(QueryException::class);

        PhotoUploadSession::factory()->create(['public_id' => $first->public_id]);
    }

    public function test_token_hash_is_unique(): void
    {
        $first = PhotoUploadSession::factory()->create();

        $this->expectException(QueryException::class);

        PhotoUploadSession::factory()->create(['token_hash' => $first->token_hash]);
    }

    public function test_raw_bearer_secret_is_not_a_persisted_column(): void
    {
        ['token' => $token] = PhotoUploadSession::issue();

        $columns = Schema::getColumnListing('photo_upload_sessions');

        $this->assertNotContains('token', $columns);
        $this->assertNotContains('raw_token', $columns);

        foreach (PhotoUploadSession::all() as $session) {
            $this->assertNotSame($token, $session->token_hash);
        }
    }

    public function test_generated_raw_token_hashes_to_the_stored_token_hash(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();

        $this->assertSame(hash('sha256', $token), $session->token_hash);
    }

    public function test_two_issued_sessions_do_not_share_a_token_or_public_id(): void
    {
        ['session' => $a, 'token' => $tokenA] = PhotoUploadSession::issue();
        ['session' => $b, 'token' => $tokenB] = PhotoUploadSession::issue();

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertNotSame($a->token_hash, $b->token_hash);
        $this->assertNotSame($a->public_id, $b->public_id);
    }

    public function test_session_has_many_uploads(): void
    {
        $session = PhotoUploadSession::factory()->create();

        PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id, 'display_order' => 1]);
        PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id, 'display_order' => 2]);
        PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id, 'display_order' => 3]);

        $this->assertCount(3, $session->photoUploads);
    }

    public function test_session_may_have_a_null_examination_id(): void
    {
        $session = PhotoUploadSession::factory()->create();

        $this->assertNull($session->examination_id);
        $this->assertNull($session->examination);
    }

    public function test_session_can_be_associated_to_an_examination(): void
    {
        $examination = $this->createExamination();

        $session = PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);

        $this->assertTrue($session->examination->is($examination));
    }

    public function test_examination_id_uniqueness_prevents_two_sessions_claiming_the_same_examination(): void
    {
        $examination = $this->createExamination();

        PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);

        $this->expectException(QueryException::class);

        PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);
    }

    public function test_deleting_a_session_cascades_its_photo_uploads(): void
    {
        $session = PhotoUploadSession::factory()->create();
        $upload = PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id]);

        $session->delete();

        $this->assertDatabaseMissing('photo_uploads', ['id' => $upload->id]);
    }

    public function test_deleting_a_finalized_examination_cascades_its_upload_session(): void
    {
        $examination = $this->createExamination();
        $session = PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);

        $examination->delete();

        $this->assertDatabaseMissing('photo_upload_sessions', ['id' => $session->id]);
    }

    public function test_deleting_an_examination_cascades_through_its_session_to_photo_uploads(): void
    {
        $examination = $this->createExamination();
        $session = PhotoUploadSession::factory()->create(['examination_id' => $examination->id]);
        $upload = PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id]);

        // A single FK-driven delete, not application code walking the relations manually.
        $examination->delete();

        $this->assertDatabaseMissing('examinations', ['id' => $examination->id]);
        $this->assertDatabaseMissing('photo_upload_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('photo_uploads', ['id' => $upload->id]);
    }

    public function test_fixed_expiry_is_24_hours_when_created_through_the_canonical_api(): void
    {
        $now = now();

        $this->travelTo($now, function () use ($now) {
            ['session' => $session] = PhotoUploadSession::issue();

            $this->assertEqualsWithDelta(
                $now->clone()->addDay()->getTimestamp(),
                $session->expires_at->getTimestamp(),
                1,
            );
        });
    }

    public function test_unfinalized_expired_sessions_are_discoverable_as_abandoned(): void
    {
        $abandoned = PhotoUploadSession::factory()->expired()->create();

        $found = PhotoUploadSession::query()
            ->whereNull('examination_id')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $this->assertTrue($found->contains($abandoned->id));
    }

    public function test_unexpired_session_is_excluded_from_the_abandoned_query(): void
    {
        $fresh = PhotoUploadSession::factory()->create();

        $found = PhotoUploadSession::query()
            ->whereNull('examination_id')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $this->assertFalse($found->contains($fresh->id));
    }

    public function test_finalized_expired_session_is_excluded_from_the_abandoned_query(): void
    {
        $examination = $this->createExamination();

        $finalizedButExpired = PhotoUploadSession::factory()->expired()->create([
            'examination_id' => $examination->id,
        ]);

        $found = PhotoUploadSession::query()
            ->whereNull('examination_id')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $this->assertFalse($found->contains($finalizedButExpired->id));
    }

    public function test_no_global_mass_assignment_weakening_is_introduced(): void
    {
        $session = new PhotoUploadSession;

        $this->assertSame([], $session->getFillable());
    }

    private function createExamination(): Examination
    {
        return Examination::create([
            'submission_no' => 'ZB-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'agent_name' => 'Test Agent',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Test Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::cases()[0],
            'form_type' => FormType::cases()[0],
            'container_status' => ContainerStatus::cases()[0],
            'attending_officer_type' => AttendingOfficerType::cases()[0],
            'submitted_at' => now(),
        ]);
    }
}
