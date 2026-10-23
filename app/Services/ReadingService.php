<?php

namespace App\Services;

use App\Contracts\ReadingsApiClient;
use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Exceptions\ReadingsApiException;
use App\Exceptions\ReadingsUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lookup order: cache -> database -> Katameros API.
 * Anything found further down is written back to the database and the cache.
 */
class ReadingService
{
    public function __construct(
        private readonly ReadingsApiClient $api,
        private readonly ReadingStore $store,
        private readonly CopticCalendarService $calendar,
    ) {}

    public function getForDate(CarbonImmutable|string $date, Language $language): DailyReadingDto
    {
        $date = $this->day($date);

        return $this->fromCache($date, $language)
            ?? $this->fromDatabase($date, $language)
            ?? $this->resolveMissing($date, $language);
    }

    /** True when the day is already in the database (the warm command skips these). */
    public function isStored(CarbonImmutable|string $date, Language $language): bool
    {
        return $this->store->has($this->day($date), $language);
    }

    // ------------------------------------------------------------------ lookup steps

    private function fromCache(CarbonImmutable $date, Language $language): ?DailyReadingDto
    {
        $hit = $this->cache()->get(ReadingCacheKey::for($language, $date));
        if (! is_array($hit) || ! isset($hit['payload'])) {
            return null;
        }

        return DailyReadingDto::fromPayload($date, $language, ReadingSource::Cache, $hit['payload']);
    }

    private function fromDatabase(CarbonImmutable $date, Language $language): ?DailyReadingDto
    {
        $dto = $this->store->find($date, $language);
        if ($dto !== null) {
            $this->remember($dto);
        }

        return $dto;
    }

    private function resolveMissing(CarbonImmutable $date, Language $language): DailyReadingDto
    {
        /** @var LockProvider $store */
        $store = $this->cache()->getStore();
        $lock = $store->lock(
            ReadingCacheKey::lock($language, $date),
            config('readings.cache.lock_seconds'),
        );

        try {
            // Simultaneous requests queue here; only the first one reaches the API.
            return $lock->block(
                config('readings.cache.lock_wait_seconds'),
                fn () => $this->fromCache($date, $language)
                    ?? $this->fromDatabase($date, $language)
                    ?? $this->fromApi($date, $language),
            );
        } catch (LockTimeoutException $e) {
            return $this->fromCache($date, $language)
                ?? $this->fromDatabase($date, $language)
                ?? throw new ReadingsUnavailableException($date->toDateString(), $language, $e);
        }
    }

    private function fromApi(CarbonImmutable $date, Language $language): DailyReadingDto
    {
        try {
            return $this->persist($this->api->fetch($date, $language));
        } catch (ReadingsApiException $e) {
            Log::warning('Readings API failed', ['date' => $date->toDateString(), 'lang' => $language->code(), 'error' => $e->getMessage()]);

            throw new ReadingsUnavailableException($date->toDateString(), $language, $e);
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Saves to the database and cache. Empty days are neither stored nor cached. */
    private function persist(DailyReadingDto $dto): DailyReadingDto
    {
        if ($this->store->save($dto)) {
            $this->remember($dto);
        }

        return $dto;
    }

    private function remember(DailyReadingDto $dto): void
    {
        if ($dto->isEmpty()) {
            return;
        }

        $this->cache()->put(
            ReadingCacheKey::for($dto->language, $dto->date),
            ['payload' => $dto->toPayload()],
            $this->ttlFor($dto->date),
        );
    }

    /** Today expires at midnight Africa/Cairo; every other day after the configured number of days. */
    private function ttlFor(CarbonImmutable $date): \DateTimeInterface
    {
        $today = $this->calendar->today();

        if ($date->toDateString() === $today->toDateString()) {
            return $today->addDay(); // midnight at the end of today (Cairo)
        }

        return now()->addDays(config('readings.cache.ttl_days'));
    }

    private function day(CarbonImmutable|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, config('readings.timezone'));
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config('readings.timezone'));
    }

    private function cache(): Repository
    {
        return Cache::store(config('readings.cache.store'));
    }
}
