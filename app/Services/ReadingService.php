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
 *
 * $bibleId is the Bible version; 0 (or the language's default id) means the default Bible.
 */
class ReadingService
{
    public function __construct(
        private readonly ReadingsApiClient $api,
        private readonly ReadingStore $store,
        private readonly CopticCalendarService $calendar,
        private readonly BibleCatalog $bibles,
    ) {}

    public function getForDate(CarbonImmutable|string $date, Language $language, int $bibleId = 0): DailyReadingDto
    {
        $date = $this->day($date);
        $bibleId = $this->bibles->normalize($language, $bibleId);

        return $this->fromCache($date, $language, $bibleId)
            ?? $this->fromDatabase($date, $language, $bibleId)
            ?? $this->resolveMissing($date, $language, $bibleId);
    }

    /** True when the day is already in the database (the warm command skips these). */
    public function isStored(CarbonImmutable|string $date, Language $language, int $bibleId = 0): bool
    {
        return $this->store->has($this->day($date), $language, $this->bibles->normalize($language, $bibleId));
    }

    /**
     * Fetch the day again and replace the stored copy (to pick up corrections made upstream).
     * If the API fails or returns nothing readable, the stored copy is left exactly as it was.
     *
     * @throws ReadingsUnavailableException
     */
    public function refresh(CarbonImmutable|string $date, Language $language, int $bibleId = 0): DailyReadingDto
    {
        $date = $this->day($date);
        $bibleId = $this->bibles->normalize($language, $bibleId);

        try {
            return $this->withLock($date, $language, $bibleId, fn () => $this->fromApi($date, $language, $bibleId));
        } catch (LockTimeoutException $e) {
            throw new ReadingsUnavailableException($date->toDateString(), $language, $e);
        }
    }

    // ------------------------------------------------------------------ lookup steps

    private function fromCache(CarbonImmutable $date, Language $language, int $bibleId): ?DailyReadingDto
    {
        $hit = $this->cache()->get(ReadingCacheKey::for($language, $date, $bibleId));
        if (! is_array($hit) || ! isset($hit['payload'])) {
            return null;
        }

        return DailyReadingDto::fromPayload($date, $language, ReadingSource::Cache, $hit['payload'], $bibleId);
    }

    private function fromDatabase(CarbonImmutable $date, Language $language, int $bibleId): ?DailyReadingDto
    {
        $dto = $this->store->find($date, $language, $bibleId);
        if ($dto !== null) {
            $this->remember($dto);
        }

        return $dto;
    }

    private function resolveMissing(CarbonImmutable $date, Language $language, int $bibleId): DailyReadingDto
    {
        try {
            // Simultaneous requests queue on the lock; only the first one reaches the API.
            return $this->withLock(
                $date,
                $language,
                $bibleId,
                fn () => $this->fromCache($date, $language, $bibleId)
                    ?? $this->fromDatabase($date, $language, $bibleId)
                    ?? $this->fromApi($date, $language, $bibleId),
            );
        } catch (LockTimeoutException $e) {
            return $this->fromCache($date, $language, $bibleId)
                ?? $this->fromDatabase($date, $language, $bibleId)
                ?? throw new ReadingsUnavailableException($date->toDateString(), $language, $e);
        }
    }

    private function fromApi(CarbonImmutable $date, Language $language, int $bibleId): DailyReadingDto
    {
        try {
            return $this->persist($this->api->fetch($date, $language, $bibleId));
        } catch (ReadingsApiException $e) {
            Log::warning('Readings API failed', ['date' => $date->toDateString(), 'lang' => $language->code(), 'error' => $e->getMessage()]);

            throw new ReadingsUnavailableException($date->toDateString(), $language, $e);
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @param callable(): mixed $callback */
    private function withLock(CarbonImmutable $date, Language $language, int $bibleId, callable $callback): mixed
    {
        /** @var LockProvider $store */
        $store = $this->cache()->getStore();
        $lock = $store->lock(ReadingCacheKey::lock($language, $date, $bibleId), config('readings.cache.lock_seconds'));

        return $lock->block(config('readings.cache.lock_wait_seconds'), $callback);
    }

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
            ReadingCacheKey::for($dto->language, $dto->date, $dto->bibleId),
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
