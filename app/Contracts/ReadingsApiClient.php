<?php

namespace App\Contracts;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use App\Exceptions\ReadingsApiException;
use Carbon\CarbonImmutable;

interface ReadingsApiClient
{
    /**
     * Fetch one day's readings from the remote source.
     * $bibleId 0 asks for the language's default Bible.
     *
     * @throws ReadingsApiException when the source is unreachable, errors, or returns no readable text
     */
    public function fetch(CarbonImmutable $date, Language $language, int $bibleId = 0): DailyReadingDto;
}
