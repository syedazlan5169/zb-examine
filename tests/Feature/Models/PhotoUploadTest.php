<?php

namespace Tests\Feature\Models;

use App\Enums\PhotoUploadStatus;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use DatabaseMigrations;

    public function test_public_id_is_automatically_generated(): void
    {
        $upload = PhotoUpload::factory()->create(['public_id' => null]);

        $this->assertNotEmpty($upload->public_id);
        $this->assertSame(26, strlen($upload->public_id));
    }

    public function test_public_id_is_unique(): void
    {
        $first = PhotoUpload::factory()->create();

        $this->expectException(QueryException::class);

        PhotoUpload::factory()->create(['public_id' => $first->public_id]);
    }

    public function test_storage_path_is_unique(): void
    {
        $first = PhotoUpload::factory()->create();

        $this->expectException(QueryException::class);

        PhotoUpload::factory()->create(['storage_path' => $first->storage_path]);
    }

    public function test_upload_belongs_to_its_session(): void
    {
        $session = PhotoUploadSession::factory()->create();
        $upload = PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id]);

        $this->assertTrue($upload->photoUploadSession->is($session));
    }

    public function test_nullable_metadata_fields_are_supported_before_verification(): void
    {
        $upload = PhotoUpload::factory()->pending()->create([
            'mime_type' => null,
            'file_size' => null,
            'width' => null,
            'height' => null,
        ]);

        $upload->refresh();

        $this->assertNull($upload->mime_type);
        $this->assertNull($upload->file_size);
        $this->assertNull($upload->width);
        $this->assertNull($upload->height);
        $this->assertNull($upload->verified_at);
    }

    public function test_verified_metadata_and_verified_at_persist_correctly(): void
    {
        $upload = PhotoUpload::factory()->create([
            'mime_type' => 'image/jpeg',
            'file_size' => 512_000,
            'width' => 2400,
            'height' => 1800,
        ]);

        $upload->refresh();

        $this->assertSame('image/jpeg', $upload->mime_type);
        $this->assertSame(512_000, $upload->file_size);
        $this->assertSame(2400, $upload->width);
        $this->assertSame(1800, $upload->height);
        $this->assertNotNull($upload->verified_at);
    }

    public function test_display_order_persists_1_based_values_correctly(): void
    {
        $session = PhotoUploadSession::factory()->create();

        foreach (range(1, 5) as $order) {
            PhotoUpload::factory()->create([
                'photo_upload_session_id' => $session->id,
                'display_order' => $order,
            ]);
        }

        $ordered = $session->photoUploads()->pluck('display_order')->all();

        $this->assertSame([1, 2, 3, 4, 5], $ordered);
    }

    public function test_deleting_the_parent_session_cascades_this_upload(): void
    {
        $session = PhotoUploadSession::factory()->create();
        $upload = PhotoUpload::factory()->create(['photo_upload_session_id' => $session->id]);

        $session->delete();

        $this->assertDatabaseMissing('photo_uploads', ['id' => $upload->id]);
    }

    public function test_casts_produce_correct_types(): void
    {
        $upload = PhotoUpload::factory()->create();

        $this->assertIsInt($upload->file_size);
        $this->assertIsInt($upload->width);
        $this->assertIsInt($upload->height);
        $this->assertIsInt($upload->display_order);
        $this->assertInstanceOf(Carbon::class, $upload->verified_at);
    }

    public function test_no_status_column_or_enum_exists(): void
    {
        $columns = Schema::getColumnListing('photo_uploads');

        $this->assertNotContains('status', $columns);
        $this->assertFalse(enum_exists(PhotoUploadStatus::class));
    }

    public function test_no_global_mass_assignment_weakening_is_introduced(): void
    {
        $upload = new PhotoUpload;

        $this->assertSame([], $upload->getFillable());
    }
}
