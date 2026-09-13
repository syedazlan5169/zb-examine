<?php

namespace App\Console\Commands;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\ExaminationReason;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\ExaminationPhoto;
use App\Services\PhotoUploadObjectPath;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LegacyImportGoogleForm extends Command
{
    protected $signature = 'legacy:import-google-form
        {manifest : JSONL manifest path}
        {--dry-run : Validate and report without database writes}
        {--checksum= : SHA-256 checksum file, defaulting to manifest.sha256 when present}';

    protected $description = 'Validate or import the prepared legacy Google Form manifest';

    /** @var array<string, class-string> */
    private const ENUMS = [
        'location' => ExaminationLocation::class,
        'form_type' => FormType::class,
        'container_status' => ContainerStatus::class,
        'reason' => ExaminationReason::class,
        'attending_officer_type' => AttendingOfficerType::class,
    ];

    /** @var array<string, int> */
    private array $summary = [
        'planned_inserts' => 0,
        'already_imported' => 0,
        'collisions' => 0,
        'invalid_rows' => 0,
        'customs_rows' => 0,
        'photo_rows' => 0,
    ];

    /** @var array<string, int> */
    private array $sequenceMaxima = [];

    private bool $hadCollision = false;

    public function handle(): int
    {
        $manifestPath = $this->argument('manifest');

        if (! is_string($manifestPath) || ! is_file($manifestPath)) {
            $this->error('Manifest file does not exist.');

            return self::FAILURE;
        }

        if (! $this->verifyChecksum($manifestPath)) {
            return self::FAILURE;
        }

        $handle = fopen($manifestPath, 'rb');

        if ($handle === false) {
            $this->error('Unable to open manifest.');

            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                    if (! is_array($record) || ($record['type'] ?? null) !== 'examination') {
                        throw new \InvalidArgumentException('record_type_invalid');
                    }

                    $this->processRecord($record);
                } catch (Throwable $exception) {
                    $this->summary['invalid_rows']++;
                    $this->line(json_encode([
                        'line' => $lineNumber,
                        'status' => 'INVALID',
                        'reason' => $this->errorCode($exception),
                    ], JSON_THROW_ON_ERROR));
                    $exitCode = self::FAILURE;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($this->hadCollision) {
            $exitCode = self::FAILURE;
        }

        $this->newLine();
        $this->line(json_encode([
            'dry_run' => (bool) $this->option('dry-run'),
            'summary' => $this->summary,
            'sequence_maxima' => $this->sequenceMaxima,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $exitCode;
    }

    /** @param array<string, mixed> $record */
    private function processRecord(array $record): void
    {
        $validated = $this->validateRecord($record);
        $submissionNo = $validated['submission_no'];
        $existing = Examination::withTrashed()->where('submission_no', $submissionNo)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $this->summary['collisions']++;
                $this->hadCollision = true;
                $this->line($submissionNo.' COLLISION');

                return;
            }
            if ($this->isIdentical($existing, $validated)) {
                $this->summary['already_imported']++;
                $this->line($submissionNo.' ALREADY_IMPORTED');

                return;
            }

            $this->summary['collisions']++;
            $this->hadCollision = true;
            $this->line($submissionNo.' COLLISION');

            return;
        }

        $this->summary['planned_inserts']++;
        $this->summary['customs_rows'] += count($validated['customs_form_numbers']);
        $this->summary['photo_rows'] += count($validated['photos']);
        $this->recordSequenceMaximum($validated['submitted_at_utc'], $submissionNo);

        if ($this->option('dry-run')) {
            $this->line($submissionNo.' PLANNED');

            return;
        }

        DB::transaction(function () use ($validated): void {
            $timestamp = $validated['submitted_at_utc'];
            $examination = new Examination;
            $examination->forceFill([
                'submission_no' => $validated['submission_no'],
                'user_id' => null,
                'agent_name' => $validated['agent_name'],
                'agent_phone' => $validated['agent_phone'],
                'agent_code' => $validated['agent_code'],
                'agent_company_name' => $validated['agent_company_name'],
                'agent_station_code' => $validated['agent_station_code'],
                'location' => $validated['location'],
                'form_type' => $validated['form_type'],
                'form_type_other' => $validated['form_type_other'],
                'container_status' => $validated['container_status'],
                'reason' => $validated['reason'],
                'reason_other' => $validated['reason_other'],
                'attending_officer_type' => $validated['attending_officer_type'],
                'submitted_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $examination->save();

            foreach ($validated['customs_form_numbers'] as $index => $number) {
                $customs = new ExaminationCustomsFormNumber;
                $customs->forceFill([
                    'examination_id' => $examination->id,
                    'number' => $number,
                    'display_order' => $index + 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $customs->save();
            }

            foreach ($validated['photos'] as $photo) {
                $evidence = new ExaminationPhoto;
                $evidence->forceFill([
                    'examination_id' => $examination->id,
                    'storage_disk' => $photo['storage_disk'],
                    'storage_path' => $photo['storage_path'],
                    'mime_type' => $photo['mime_type'],
                    'file_size' => $photo['file_size'],
                    'width' => $photo['width'],
                    'height' => $photo['height'],
                    'display_order' => $photo['display_order'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $evidence->save();
            }

            $this->synchronizeSequence($validated['submitted_at_utc'], $validated['submission_no']);
        });

        $this->line($submissionNo.' IMPORTED');
    }

    /** @param array<string, mixed> $record */
    private function validateRecord(array $record): array
    {
        $required = [
            'manifest_version', 'source_row', 'submission_no', 'submitted_at_utc', 'user_id', 'agent_name', 'agent_phone',
            'agent_code', 'agent_company_name', 'agent_station_code', 'location', 'form_type',
            'form_type_other', 'container_status', 'reason', 'reason_other',
            'attending_officer_type', 'customs_form_numbers', 'photos',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $record)) {
                throw new \InvalidArgumentException('missing_'.$key);
            }
        }

        if ($record['manifest_version'] !== 1) {
            throw new \InvalidArgumentException('manifest_version_invalid');
        }

        if (! is_int($record['source_row']) || $record['source_row'] < 2) {
            throw new \InvalidArgumentException('source_row_invalid');
        }

        if (! is_string($record['submitted_at_utc']) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $record['submitted_at_utc']) !== 1) {
            throw new \InvalidArgumentException('submitted_at_utc_invalid');
        }

        $submittedAt = CarbonImmutable::parse((string) $record['submitted_at_utc'])->utc();
        $submissionNo = (string) $record['submission_no'];

        if (preg_match('/^ZB-(\d{6})-(\d{4})$/D', $submissionNo, $matches) !== 1) {
            throw new \InvalidArgumentException('submission_number_invalid');
        }

        if ((int) $matches[2] < 1 || (int) $matches[2] > 9999) {
            throw new \InvalidArgumentException('submission_sequence_invalid');
        }

        $businessDate = $submittedAt->setTimezone(config('zb-examine.business_timezone'))->toDateString();
        if ($businessDate < '2026-01-01' || $businessDate > '2026-09-11') {
            throw new \InvalidArgumentException('historical_date_out_of_range');
        }

        if ($submittedAt->setTimezone(config('zb-examine.business_timezone'))->format('ymd') !== $matches[1]) {
            throw new \InvalidArgumentException('submission_date_mismatch');
        }

        foreach (self::ENUMS as $field => $enum) {
            if ($record[$field] === null && $field === 'reason') {
                continue;
            }
            if (! is_string($record[$field]) || ! enum_exists($enum) || $enum::tryFrom($record[$field]) === null) {
                throw new \InvalidArgumentException('enum_'.$field.'_invalid');
            }
        }

        if (($record['user_id'] ?? null) !== null) {
            throw new \InvalidArgumentException('user_id_must_be_null');
        }

        foreach ([
            'agent_name' => 255,
            'agent_phone' => 30,
            'agent_code' => 50,
            'agent_company_name' => 255,
            'agent_station_code' => 50,
        ] as $field => $maximum) {
            if (! is_string($record[$field]) || trim($record[$field]) === '' || mb_strlen($record[$field]) > $maximum) {
                throw new \InvalidArgumentException($field.'_invalid');
            }
        }

        if ($record['form_type'] === 'other') {
            if (! is_string($record['form_type_other']) || trim($record['form_type_other']) === '' || mb_strlen($record['form_type_other']) > 255) {
                throw new \InvalidArgumentException('form_type_other_invalid');
            }
        } elseif ($record['form_type_other'] !== null) {
            throw new \InvalidArgumentException('form_type_other_must_be_null');
        }

        if ($record['reason'] === 'other') {
            if (! is_string($record['reason_other']) || trim($record['reason_other']) === '' || mb_strlen($record['reason_other']) > 255) {
                throw new \InvalidArgumentException('reason_other_invalid');
            }
        } elseif ($record['reason_other'] !== null) {
            throw new \InvalidArgumentException('reason_other_must_be_null');
        }

        if (! is_array($record['customs_form_numbers']) || $record['customs_form_numbers'] === []) {
            throw new \InvalidArgumentException('customs_forms_invalid');
        }

        $customs = [];
        foreach ($record['customs_form_numbers'] as $number) {
            $number = trim((string) $number);
            if ($number === '' || mb_strlen($number) > 100 || in_array(mb_strtolower($number), array_map('mb_strtolower', $customs), true)) {
                throw new \InvalidArgumentException('customs_form_invalid');
            }
            $customs[] = $number;
        }

        if (! is_array($record['photos']) || count($record['photos']) < 1 || count($record['photos']) > 10) {
            throw new \InvalidArgumentException('photos_invalid');
        }

        $photos = [];
        foreach (array_values($record['photos']) as $index => $photo) {
            if (! is_array($photo) || ($photo['display_order'] ?? null) !== $index + 1) {
                throw new \InvalidArgumentException('photo_order_invalid');
            }
            if (($photo['storage_disk'] ?? null) !== 'photo_uploads_spaces' || ($photo['mime_type'] ?? null) !== 'image/jpeg' || ! PhotoUploadObjectPath::isSpacesFinalized((string) ($photo['storage_path'] ?? ''))) {
                throw new \InvalidArgumentException('photo_storage_invalid');
            }
            foreach (['file_size', 'width', 'height'] as $field) {
                if (! is_int($photo[$field] ?? null) || $photo[$field] <= 0) {
                    throw new \InvalidArgumentException('photo_'.$field.'_invalid');
                }
            }
            $photos[] = $photo;
        }

        return array_merge($record, [
            'submission_no' => $submissionNo,
            'submitted_at_utc' => $submittedAt,
            'customs_form_numbers' => $customs,
            'photos' => $photos,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function isIdentical(Examination $existing, array $record): bool
    {
        foreach (['submission_no', 'agent_name', 'agent_phone', 'agent_code', 'agent_company_name', 'agent_station_code', 'location', 'form_type', 'form_type_other', 'container_status', 'reason', 'reason_other', 'attending_officer_type'] as $field) {
            if ((string) $existing->getRawOriginal($field) !== (string) ($record[$field] ?? null)) {
                return false;
            }
        }

        if ($existing->user_id !== null || $existing->submitted_at->format('Y-m-d H:i:s') !== $record['submitted_at_utc']->format('Y-m-d H:i:s')) {
            return false;
        }

        $customs = $existing->customsFormNumbers()->orderBy('display_order')->get()->map(fn ($row): array => [$row->number, $row->display_order])->all();
        $expectedCustoms = collect($record['customs_form_numbers'])->values()->map(fn ($number, $index): array => [$number, $index + 1])->all();
        $photos = $existing->photos()->orderBy('display_order')->get()->map(fn ($row): array => [$row->storage_disk, $row->storage_path, $row->mime_type, $row->file_size, $row->width, $row->height, $row->display_order])->all();
        $expectedPhotos = collect($record['photos'])->map(fn ($photo): array => [$photo['storage_disk'], $photo['storage_path'], $photo['mime_type'], $photo['file_size'], $photo['width'], $photo['height'], $photo['display_order']])->all();

        return $customs === $expectedCustoms && $photos === $expectedPhotos;
    }

    private function verifyChecksum(string $manifestPath): bool
    {
        $checksumPath = $this->option('checksum') ?: $manifestPath.'.sha256';
        if (! is_file($checksumPath)) {
            $this->error('Manifest checksum file is required.');

            return false;
        }
        $expected = strtolower(trim((string) file_get_contents($checksumPath)));
        $expected = preg_replace('/\s+.*$/', '', $expected) ?? $expected;
        $actual = hash_file('sha256', $manifestPath);
        if (! hash_equals($expected, $actual)) {
            $this->error('Manifest checksum mismatch.');

            return false;
        }

        return true;
    }

    private function recordSequenceMaximum(CarbonImmutable $submittedAt, string $submissionNo): void
    {
        $date = $submittedAt->setTimezone(config('zb-examine.business_timezone'))->toDateString();
        $sequence = (int) substr($submissionNo, -4);
        $this->sequenceMaxima[$date] = max($this->sequenceMaxima[$date] ?? 0, $sequence);
    }

    private function synchronizeSequence(CarbonImmutable $submittedAt, string $submissionNo): void
    {
        $date = $submittedAt->setTimezone(config('zb-examine.business_timezone'))->toDateString();
        $sequence = (int) substr($submissionNo, -4);
        $now = now();
        DB::table('submission_sequences')->upsert(
            [['sequence_date' => $date, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]],
            ['sequence_date'],
            ['sequence_date'],
        );
        DB::table('submission_sequences')
            ->where('sequence_date', $date)
            ->where('last_number', '<', $sequence)
            ->update(['last_number' => $sequence, 'updated_at' => $now]);
    }

    private function errorCode(Throwable $exception): string
    {
        return preg_replace('/[^a-z0-9_]+/i', '_', strtolower($exception->getMessage())) ?: 'invalid_record';
    }
}
