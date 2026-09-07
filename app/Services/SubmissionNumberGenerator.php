<?php

namespace App\Services;

use App\Exceptions\SubmissionNumberAllocationInsideTransaction;
use App\Exceptions\SubmissionNumberSequenceExhausted;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SubmissionNumberGenerator
{
    public const MAX_DAILY_SEQUENCE = 9999;

    /**
     * Atomically allocate and format the next submission number for the
     * business date derived from the given instant (or now).
     *
     * @throws SubmissionNumberAllocationInsideTransaction
     * @throws SubmissionNumberSequenceExhausted
     */
    public function generate(?CarbonInterface $instant = null): string
    {
        if (DB::connection()->transactionLevel() > 0) {
            throw new SubmissionNumberAllocationInsideTransaction;
        }

        $instant = $instant !== null ? Carbon::instance($instant) : Carbon::now();

        $businessDate = $instant->setTimezone(config('zb-examine.business_timezone'))->toDateString();

        return DB::transaction(function () use ($businessDate) {
            $now = Carbon::now();

            // Atomic no-op upsert avoids a duplicate-key race on the first allocation of a date.
            DB::table('submission_sequences')->upsert(
                [['sequence_date' => $businessDate, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]],
                ['sequence_date'],
                ['sequence_date'],
            );

            $row = DB::table('submission_sequences')
                ->where('sequence_date', $businessDate)
                ->lockForUpdate()
                ->first();

            $next = $row->last_number + 1;

            if ($next > self::MAX_DAILY_SEQUENCE) {
                throw new SubmissionNumberSequenceExhausted($businessDate);
            }

            DB::table('submission_sequences')
                ->where('sequence_date', $businessDate)
                ->update([
                    'last_number' => $next,
                    'updated_at' => $now,
                ]);

            return $this->format($businessDate, $next);
        }, 3);
    }

    private function format(string $businessDate, int $sequence): string
    {
        $date = Carbon::createFromFormat('Y-m-d', $businessDate);

        return sprintf('ZB-%s-%04d', $date->format('ymd'), $sequence);
    }
}
