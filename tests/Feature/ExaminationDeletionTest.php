<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExaminationDeletionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_only_admin_can_delete_an_examination(): void
    {
        $examination = Examination::factory()->create();

        $this->delete(route('examinations.destroy', $examination))
            ->assertRedirect(route('login'));

        foreach ([User::factory()->agent()->create(), User::factory()->officer()->create()] as $user) {
            $this->actingAs($user)
                ->delete(route('examinations.destroy', $examination))
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('examinations.destroy', $examination))
            ->assertRedirect(route('examinations.index'));
    }

    public function test_admin_soft_deletes_and_audits_without_removing_evidence(): void
    {
        Storage::fake('photo_uploads');
        $examination = Examination::factory()->create(['submission_no' => 'ZB-DELETE-001']);
        $photo = ExaminationPhoto::factory()->for($examination)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'photo-uploads/finalized/delete-test.jpg',
        ]);
        Storage::disk('photo_uploads')->put($photo->storage_path, 'evidence');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('examinations.destroy', $examination))
            ->assertRedirect(route('examinations.index'))
            ->assertSessionHas('status', __('examination.staff.deleted'));

        $deleted = Examination::withTrashed()->findOrFail($examination->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($admin->id, $deleted->deleted_by_user_id);
        $this->assertDatabaseHas('examination_photos', ['id' => $photo->id, 'storage_path' => $photo->storage_path]);
        Storage::disk('photo_uploads')->assertExists($photo->storage_path);
        $this->assertDatabaseMissing('examinations', ['id' => $examination->id, 'deleted_at' => null]);
    }

    public function test_deleted_examination_is_excluded_from_normal_list_search_and_detail(): void
    {
        $examination = Examination::factory()->create(['submission_no' => 'ZB-DELETE-002']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete(route('examinations.destroy', $examination));

        $this->actingAs($admin)
            ->get(route('examinations.index', ['today' => '0', 'search' => 'ZB-DELETE-002']))
            ->assertOk()
            ->assertSee(__('examination.staff.no_search_results'))
            ->assertDontSee('href="'.route('examinations.show', $examination).'"', false);

        $this->actingAs($admin)
            ->get(route('examinations.show', $examination))
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('examinations.destroy', $examination))
            ->assertNotFound();
    }

    public function test_delete_control_is_visible_only_to_admins(): void
    {
        $examination = Examination::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('examinations.show', [$examination, 'today' => '0']))
            ->assertSee('data-delete-trigger', false)
            ->assertSee(__('examination.staff.delete_title'));

        foreach ([User::factory()->officer()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('examinations.show', [$examination, 'today' => '0']))
                ->assertDontSee('data-delete-trigger', false);
        }
    }
}
