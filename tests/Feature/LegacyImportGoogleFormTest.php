<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\ExaminationPhoto;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LegacyImportGoogleFormTest extends TestCase
{
    use DatabaseMigrations;

    public function test_dry_run_validates_without_writing_anything(): void
    {
        $manifest = $this->manifestPath([$this->record()]);

        $this->artisan('legacy:import-google-form', [
            'manifest' => $manifest,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, Examination::withTrashed()->count());
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertSame(0, ExaminationPhoto::count());
        $this->assertSame(0, DB::table('submission_sequences')->count());
    }

    public function test_missing_or_invalid_checksum_fails_before_validation(): void
    {
        $manifest = $this->manifestPath([$this->record()]);
        unlink($manifest.'.sha256');

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest, '--dry-run' => true])
            ->assertExitCode(1);
        $this->assertSame(0, Examination::count());

        file_put_contents($manifest.'.sha256', str_repeat('0', 64).'  '.basename($manifest)."\n");
        $this->artisan('legacy:import-google-form', ['manifest' => $manifest, '--dry-run' => true])
            ->assertExitCode(1);
    }

    public function test_import_is_idempotent_and_synchronizes_sequence_monotonically(): void
    {
        $manifest = $this->manifestPath([$this->record()]);

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
            ->assertExitCode(0);

        $this->assertSame(1, Examination::count());
        $this->assertSame(1, ExaminationCustomsFormNumber::count());
        $this->assertSame(1, ExaminationPhoto::count());
        $this->assertSame(1, DB::table('submission_sequences')->value('last_number'));

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
            ->assertExitCode(0);

        $this->assertSame(1, Examination::count());
        $this->assertSame(1, ExaminationCustomsFormNumber::count());
        $this->assertSame(1, ExaminationPhoto::count());
        $this->assertSame(1, DB::table('submission_sequences')->value('last_number'));
    }

    public function test_mismatched_existing_submission_is_not_mutated(): void
    {
        Examination::factory()->create([
            'submission_no' => 'ZB-260102-0001',
            'agent_name' => 'Existing record',
        ]);
        $manifest = $this->manifestPath([$this->record()]);

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
            ->assertExitCode(1);

        $this->assertSame('Existing record', Examination::firstOrFail()->agent_name);
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertSame(0, ExaminationPhoto::count());
    }

    public function test_matching_parent_with_different_children_is_a_collision(): void
    {
        $manifest = $this->manifestPath([$this->record()]);
        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])->assertExitCode(0);

        $record = $this->record();
        $record['customs_form_numbers'] = ['DIFFERENT'];
        $changed = $this->manifestPath([$record]);

        $this->artisan('legacy:import-google-form', ['manifest' => $changed])
            ->assertExitCode(1);
        $this->assertSame(['B18112052109'], ExaminationCustomsFormNumber::query()->pluck('number')->all());
    }

    public function test_soft_deleted_matching_submission_is_a_collision(): void
    {
        $manifest = $this->manifestPath([$this->record()]);
        $existing = Examination::factory()->create(['submission_no' => 'ZB-260102-0001']);
        $existing->delete();

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
            ->assertExitCode(1);
        $this->assertSame(1, Examination::withTrashed()->count());
    }

    public function test_parent_and_children_roll_back_when_photo_insert_fails(): void
    {
        $manifest = $this->manifestPath([$this->record()]);
        $event = 'eloquent.creating: '.ExaminationPhoto::class;
        Event::listen($event, static function (): void {
            throw new \RuntimeException('forced photo failure');
        });

        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
            ->assertExitCode(1);
        Event::forget($event);

        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationCustomsFormNumber::count());
        $this->assertSame(0, ExaminationPhoto::count());
        $this->assertSame(0, DB::table('submission_sequences')->count());
    }

    public function test_python_generated_manifest_is_accepted_by_laravel(): void
    {
        $directory = sys_get_temp_dir().'/p13b-cross-language-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $workbook = $directory.'/fixture.xlsx';
        $state = $directory.'/state.sqlite';
        $manifest = $directory.'/manifest.jsonl';

        try {
            $script = base_path('tools/legacy-migration/legacy_migration.py');
            $result = Process::run([
                'python3', $script, 'emit-cross-language-fixture', $workbook, $state, $manifest,
            ]);

            $this->assertTrue($result->successful(), $result->errorOutput());
            $this->assertFileExists($manifest);
            $this->assertFileExists($manifest.'.sha256');

            $this->artisan('legacy:import-google-form', [
                'manifest' => $manifest,
                '--dry-run' => true,
            ])->assertExitCode(0);

            $this->artisan('legacy:import-google-form', ['manifest' => $manifest])
                ->assertExitCode(0);

            $this->assertSame(2, Examination::count());
            $this->assertSame(2, Examination::where('submission_no', 'ZB-260102-0001')->count() + Examination::where('submission_no', 'ZB-260102-0002')->count());
            $this->assertSame(3, ExaminationPhoto::count());
            $this->assertSame([1, 2], ExaminationPhoto::query()->whereHas('examination', fn ($query) => $query->where('submission_no', 'ZB-260102-0001'))->orderBy('display_order')->pluck('display_order')->all());
            $this->assertSame(0, Examination::where('agent_name', 'Invalid C')->count());
            $this->assertSame(0, ExaminationCustomsFormNumber::where('number', 'INVALID-C')->count());
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_sequence_counter_does_not_decrease_for_higher_existing_value(): void
    {
        DB::table('submission_sequences')->insert(['sequence_date' => '2026-01-10', 'last_number' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $record = $this->record();
        $record['submission_no'] = 'ZB-260110-0038';
        $record['submitted_at_utc'] = '2026-01-10T02:05:46.759000Z';
        $this->artisan('legacy:import-google-form', ['manifest' => $this->manifestPath([$record])])->assertExitCode(0);
        $this->assertSame(50, DB::table('submission_sequences')->where('sequence_date', '2026-01-10')->value('last_number'));
    }

    public function test_sequence_counter_increases_when_existing_value_is_lower(): void
    {
        DB::table('submission_sequences')->insert(['sequence_date' => '2026-01-10', 'last_number' => 20, 'created_at' => now(), 'updated_at' => now()]);
        $record = $this->record();
        $record['submission_no'] = 'ZB-260110-0038';
        $record['submitted_at_utc'] = '2026-01-10T02:05:46.759000Z';
        $this->artisan('legacy:import-google-form', ['manifest' => $this->manifestPath([$record])])->assertExitCode(0);
        $this->assertSame(38, DB::table('submission_sequences')->where('sequence_date', '2026-01-10')->value('last_number'));
    }

    public function test_already_imported_and_collision_leave_sequence_unchanged(): void
    {
        DB::table('submission_sequences')->insert(['sequence_date' => '2026-01-10', 'last_number' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $record = $this->record();
        $record['submission_no'] = 'ZB-260110-0038';
        $record['submitted_at_utc'] = '2026-01-10T02:05:46.759000Z';
        $manifest = $this->manifestPath([$record]);
        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])->assertExitCode(0);
        $this->artisan('legacy:import-google-form', ['manifest' => $manifest])->assertExitCode(0);
        $record['agent_name'] = 'Different';
        $this->artisan('legacy:import-google-form', ['manifest' => $this->manifestPath([$record])])->assertExitCode(1);
        $this->assertSame(50, DB::table('submission_sequences')->where('sequence_date', '2026-01-10')->value('last_number'));
        $this->assertSame(1, Examination::count());
    }

    public function test_dry_run_does_not_change_an_existing_sequence_counter(): void
    {
        DB::table('submission_sequences')->insert(['sequence_date' => '2026-01-10', 'last_number' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $record = $this->record();
        $record['submission_no'] = 'ZB-260110-0038';
        $record['submitted_at_utc'] = '2026-01-10T02:05:46.759000Z';
        $this->artisan('legacy:import-google-form', ['manifest' => $this->manifestPath([$record]), '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(50, DB::table('submission_sequences')->where('sequence_date', '2026-01-10')->value('last_number'));
        $this->assertSame(0, Examination::count());
    }

    /** @param list<array<string, mixed>> $records */
    private function manifestPath(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p13b-manifest-');

        if ($path === false) {
            $this->fail('Unable to create a temporary manifest.');
        }

        file_put_contents($path, implode("\n", array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR),
            $records,
        ))."\n");
        file_put_contents($path.'.sha256', hash_file('sha256', $path).'  '.basename($path)."\n");

        return $path;
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'type' => 'examination',
            'manifest_version' => 1,
            'source_row' => 2,
            'submission_no' => 'ZB-260102-0001',
            'submitted_at_utc' => '2026-01-02T02:05:46.759000Z',
            'agent_name' => 'Historical Agent',
            'agent_phone' => '0123456789',
            'agent_code' => 'BF0764',
            'agent_company_name' => 'Historical Company',
            'agent_station_code' => 'ST-1',
            'location' => 'container_gate_terminal',
            'form_type' => 'k1',
            'form_type_other' => null,
            'container_status' => 'fcl',
            'reason' => 'drawback',
            'reason_other' => null,
            'attending_officer_type' => 'customs',
            'user_id' => null,
            'customs_form_numbers' => ['B18112052109'],
            'photos' => [[
                'storage_disk' => 'photo_uploads_spaces',
                'storage_path' => 'photo-uploads/01J00000000000000000000000/01J00000000000000000000001/0123456789abcdef0123456789abcdef0123456789abcdef.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 1234,
                'width' => 1600,
                'height' => 1200,
                'display_order' => 1,
            ]],
        ];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($directory);
    }
}
