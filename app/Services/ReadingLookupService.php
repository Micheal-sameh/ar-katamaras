<?php

namespace App\Services;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use Carbon\CarbonImmutable;

/**
 * What a visitor's request for one day goes through: it returns the day (fetching it if it was not stored) and, when
 * the server had to fetch it, starts the whole-year warm-up so the next requests find it stored.
 */
class ReadingLookupService
{
    public function __construct(
        private readonly ReadingService $readings,
        private readonly YearWarmTrigger $yearWarm,
        private readonly CopticCalendarService $calendar,
    ) {}

    public function get(CarbonImmutable $date, Language $language, int $bibleId = 0): DailyReadingDto
    {
        $wasStored = $this->readings->isStored($date, $language, $bibleId);

        $day = $this->readings->getForDate($date, $language, $bibleId);

        // Only days from today on are covered by the warm-up; a missing past day would not be fixed by it.
        if (! $wasStored && $date >= $this->calendar->today()) {
            $this->yearWarm->requestIfNeeded();
        }

        return $day;
    }
}
