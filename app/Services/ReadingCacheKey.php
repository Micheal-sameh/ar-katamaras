<?php

namespace App\Services;

use App\Enums\Language;
use Carbon\CarbonInterface;

/** The only place cache and lock keys are built. */
final class ReadingCacheKey
{
    public static function for(Language $language, CarbonInterface $date): string
    {
        return sprintf('readings:%s:%s', $language->code(), $date->format('Y-m-d'));
    }

    public static function lock(Language $language, CarbonInterface $date): string
    {
        return 'lock:'.self::for($language, $date);
    }
}
