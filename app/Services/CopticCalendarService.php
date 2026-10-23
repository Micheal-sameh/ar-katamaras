<?php

namespace App\Services;

use App\DTOs\CopticDateDto;
use App\Enums\CopticMonth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CopticCalendarService
{
    /** Julian Day Number of 1 Thout 1 AM (29 August 284, Julian calendar). */
    private const EPOCH_JDN = 1825030;

    public function fromGregorian(CarbonInterface $date): CopticDateDto
    {
        $n = $this->gregorianToJdn($date->year, $date->month, $date->day) - self::EPOCH_JDN;

        $year = intdiv($n, 365) + 1;
        while ($this->yearStart($year + 1) <= $n) {
            $year++;
        }
        while ($this->yearStart($year) > $n) {
            $year--;
        }

        $dayOfYear = $n - $this->yearStart($year);

        return new CopticDateDto(
            day: ($dayOfYear % 30) + 1,
            month: CopticMonth::from(intdiv($dayOfYear, 30) + 1),
            year: $year,
        );
    }

    public function toGregorian(CopticDateDto $coptic): CarbonImmutable
    {
        $jdn = self::EPOCH_JDN + $this->yearStart($coptic->year) + ($coptic->month->value - 1) * 30 + ($coptic->day - 1);

        return $this->jdnToGregorian($jdn);
    }

    /** Coptic leap years are those where year % 4 == 3; the 13th month then has 6 days. */
    public function isLeapYear(int $copticYear): bool
    {
        return $copticYear % 4 === 3;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('readings.timezone'))->startOfDay();
    }

    private function yearStart(int $year): int
    {
        return 365 * ($year - 1) + intdiv($year, 4);
    }

    private function gregorianToJdn(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;

        return $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
    }

    private function jdnToGregorian(int $jdn): CarbonImmutable
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('readings.timezone'));
    }
}
