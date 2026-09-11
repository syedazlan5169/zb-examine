<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class ReportPeriod
{
    private function __construct(
        public int $year,
        public int $month,
        public string $timezone,
        public CarbonImmutable $localStart,
        public CarbonImmutable $localNextMonthStart,
        public CarbonImmutable $utcStart,
        public CarbonImmutable $utcNextMonthStart,
    ) {}

    public static function make(int $year, int $month, ?string $timezone = null): self
    {
        $timezone ??= (string) config('zb-examine.business_timezone', 'Asia/Kuala_Lumpur');

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('The report month must be between 1 and 12.');
        }

        if ($year < 2000 || $year > (int) now($timezone)->year + 1) {
            throw new InvalidArgumentException('The report year is outside the supported range.');
        }

        $localStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);
        $localNextMonthStart = $localStart->addMonth();

        return new self(
            year: $year,
            month: $month,
            timezone: $timezone,
            localStart: $localStart,
            localNextMonthStart: $localNextMonthStart,
            utcStart: $localStart->utc(),
            utcNextMonthStart: $localNextMonthStart->utc(),
        );
    }

    public static function current(?string $timezone = null): self
    {
        $timezone ??= (string) config('zb-examine.business_timezone', 'Asia/Kuala_Lumpur');
        $now = CarbonImmutable::now($timezone);

        return self::make($now->year, $now->month, $timezone);
    }

    public function label(): string
    {
        return $this->localStart->translatedFormat('F Y');
    }

    public function filenameLabel(): string
    {
        return $this->localStart->format('F-Y');
    }

    public function contains(DateTimeInterface $instant): bool
    {
        $utc = CarbonImmutable::instance($instant)->utc();

        return $utc >= $this->utcStart && $utc < $this->utcNextMonthStart;
    }
}
