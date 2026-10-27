<?php

namespace App\Services;

use App\Enums\Language;
use Carbon\CarbonInterface;

/** The only place cache and lock keys are built. */
final class ReadingCacheKey
{
    /** "readings:ar:2026-10-23", plus ":b3" for a non-default Bible. */
    public static function for(Language $language, CarbonInterface $date, int $bibleId = 0): string
    {
        $key = sprintf('readings:%s:%s', $language->code(), $date->format('Y-m-d'));

        return $bibleId > 0 ? $key.':b'.$bibleId : $key;
    }

    /** Error messages from the latest pre-cache run, read back by the verification email. */
    public static function warmErrors(): string
    {
        return 'readings:warm-month:errors';
    }

    /** Set while an on-demand year warm-up is cooling down (see YearWarmTrigger). */
    public static function yearWarmRequested(): string
    {
        return 'readings:year-warm:requested';
    }

    public static function lock(Language $language, CarbonInterface $date, int $bibleId = 0): string
    {
        return 'lock:'.self::for($language, $date, $bibleId);
    }

    /** Marks that the scheduler (or the queue worker) ran recently. Read by the health check. */
    public static function heartbeat(string $component): string
    {
        return 'readings:heartbeat:'.$component;
    }
}
