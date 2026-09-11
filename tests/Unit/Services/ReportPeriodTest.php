<?php

namespace Tests\Unit\Services;

use App\Data\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class ReportPeriodTest extends TestCase
{
    public function test_it_converts_the_business_month_to_half_open_utc_boundaries(): void
    {
        $period = ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur');

        $this->assertSame('2026-09-01 00:00:00', $period->localStart->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $period->localNextMonthStart->toDateTimeString());
        $this->assertSame('2026-08-31 16:00:00', $period->utcStart->toDateTimeString());
        $this->assertSame('2026-09-30 16:00:00', $period->utcNextMonthStart->toDateTimeString());

        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-09-01 00:05:00', 'Asia/Kuala_Lumpur')));
        $this->assertFalse($period->contains(CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Kuala_Lumpur')));
    }

    public function test_it_rejects_invalid_months_and_years(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::make(1999, 9, 'Asia/Kuala_Lumpur');
    }

    public function test_it_uses_the_current_business_month_by_default(): void
    {
        $period = ReportPeriod::current('Asia/Kuala_Lumpur');
        $now = CarbonImmutable::now('Asia/Kuala_Lumpur');

        $this->assertSame($now->year, $period->year);
        $this->assertSame($now->month, $period->month);
    }
}
