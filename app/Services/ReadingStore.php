<?php

namespace App\Services;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Models\DailyReading;
use App\Models\ReadingOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Database persistence for stored days and PPT overrides. Rows never expire. */
class ReadingStore
{
    public function __construct(private readonly CopticCalendarService $calendar) {}

    public function find(CarbonImmutable $date, Language $language): ?DailyReadingDto
    {
        $row = DailyReading::query()
            ->whereDate('date', $date->toDateString())
            ->where('language', $language->value)
            ->first();

        return $row === null ? null : DailyReadingDto::fromPayload($date, $language, $row->source, $row->payload);
    }

    public function has(CarbonImmutable $date, Language $language): bool
    {
        return DailyReading::query()
            ->whereDate('date', $date->toDateString())
            ->where('language', $language->value)
            ->exists();
    }

    /** Persists a day. An empty day never replaces (or creates) a stored one. */
    public function save(DailyReadingDto $dto): bool
    {
        if ($dto->isEmpty()) {
            return false;
        }

        DailyReading::query()->updateOrCreate(
            ['date' => $dto->date->toDateString(), 'language' => $dto->language->value],
            [
                'coptic_date' => sprintf('%d/%d/%d', $dto->copticDate->day, $dto->copticDate->month->value, $dto->copticDate->year),
                'payload' => $dto->toPayload(),
                'source' => $dto->source,
                'fetched_at' => now(),
            ],
        );

        return true;
    }

    public function findOverride(CarbonImmutable $date, Language $language): ?DailyReadingDto
    {
        $override = ReadingOverride::query()
            ->where('language', $language->value)
            ->where(function ($q) use ($date) {
                $q->whereDate('date', $date->toDateString())
                    ->orWhere('coptic_key', $this->calendar->fromGregorian($date)->dayKey());
            })
            ->orderByRaw('date IS NULL') // an exact date beats a recurring Coptic day
            ->first();

        return $override === null ? null : $this->overrideToDto($override, $date);
    }

    public function overrideToDto(ReadingOverride $override, CarbonImmutable $date): DailyReadingDto
    {
        $payload = $override->payload;
        $payload['copticDate'] ??= $this->calendar->fromGregorian($date)->toArray();

        return DailyReadingDto::fromPayload($date, $override->language, ReadingSource::Ppt, $payload);
    }

    /**
     * Stored days whose Coptic day matches a recurring "MM-DD" key.
     *
     * @return list<CarbonImmutable>
     */
    public function datesForCopticKey(string $copticKey, Language $language): array
    {
        [$month, $day] = array_map('intval', explode('-', $copticKey) + [0, 0]);

        return DailyReading::query()
            ->where('language', $language->value)
            ->where(function ($q) use ($day, $month) {
                $q->where('coptic_date', 'like', "{$day}/{$month}/%");
            })
            ->get(['date'])
            ->map(fn (DailyReading $r) => CarbonImmutable::parse($r->date->toDateString(), config('readings.timezone')))
            ->all();
    }

    /** @return array{first: ?string, last: ?string, count: int} */
    public function range(Language $language): array
    {
        $row = DailyReading::query()
            ->where('language', $language->value)
            ->select([DB::raw('MIN(date) as first'), DB::raw('MAX(date) as last'), DB::raw('COUNT(*) as total')])
            ->first();

        return [
            'first' => $row?->first ? substr((string) $row->first, 0, 10) : null,
            'last' => $row?->last ? substr((string) $row->last, 0, 10) : null,
            'count' => (int) ($row?->total ?? 0),
        ];
    }

    /** Consecutive stored days starting from $from (inclusive); stops at the first gap. */
    public function consecutiveDaysFrom(CarbonImmutable $from, Language $language, int $limit = 60): int
    {
        $stored = DailyReading::query()
            ->where('language', $language->value)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<', $from->addDays($limit)->toDateString())
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->flip();

        $days = 0;
        while ($days < $limit && $stored->has($from->addDays($days)->toDateString())) {
            $days++;
        }

        return $days;
    }
}
