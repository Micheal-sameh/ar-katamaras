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

    /** Error messages from the latest monthly warm-up, read back by the verification email. */
    public static function warmErrors(): string
    {
        return 'readings:warm-month:errors';
    }

    public static function lock(Language $language, CarbonInterface $date): string
    {
        return 'lock:'.self::for($language, $date);
    }
}
