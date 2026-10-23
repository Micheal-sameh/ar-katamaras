<?php

namespace Tests\Unit;

use App\Enums\CopticMonth;
use App\Enums\Language;
use App\Services\CopticCalendarService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CopticCalendarServiceTest extends TestCase
{
    private CopticCalendarService $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new CopticCalendarService;
    }

    public function test_converts_known_gregorian_dates(): void
    {
        $cases = [
            ['2026-10-23', 13, CopticMonth::Paopi, 1743],
            ['2026-09-11', 1, CopticMonth::Thout, 1743],   // Nayrouz
            ['2025-01-07', 29, CopticMonth::Koiak, 1741],  // Christmas
            ['2024-01-07', 28, CopticMonth::Koiak, 1740],  // before the leap-year shift
            ['2026-09-10', 5, CopticMonth::Nasie, 1742],
        ];

        foreach ($cases as [$date, $day, $month, $year]) {
            $coptic = $this->calendar->fromGregorian(CarbonImmutable::parse($date));
            $this->assertSame([$day, $month, $year], [$coptic->day, $coptic->month, $coptic->year], $date);
        }
    }

    public function test_leap_year_has_a_sixth_day_in_nasie(): void
    {
        $this->assertTrue($this->calendar->isLeapYear(1743));

        $sixth = $this->calendar->fromGregorian(CarbonImmutable::parse('2027-09-11'));
        $this->assertSame([6, CopticMonth::Nasie, 1743], [$sixth->day, $sixth->month, $sixth->year]);

        $newYear = $this->calendar->fromGregorian(CarbonImmutable::parse('2027-09-12'));
        $this->assertSame([1, CopticMonth::Thout, 1744], [$newYear->day, $newYear->month, $newYear->year]);
    }

    public function test_round_trips_every_day_over_several_years(): void
    {
        $date = CarbonImmutable::parse('2023-01-01');
        for ($i = 0; $i < 365 * 5; $i++, $date = $date->addDay()) {
            $back = $this->calendar->toGregorian($this->calendar->fromGregorian($date));
            $this->assertSame($date->toDateString(), $back->toDateString());
        }
    }

    public function test_day_key_and_labels(): void
    {
        $coptic = $this->calendar->fromGregorian(CarbonImmutable::parse('2026-10-23'));

        $this->assertSame('بابه', CopticMonth::Paopi->arabic());
        $this->assertSame('13 Paopi 1743', $coptic->format(Language::English));
        $this->assertSame('13 بابه 1743', $coptic->format(Language::Arabic));
    }
}
