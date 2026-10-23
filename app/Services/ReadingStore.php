<?php

namespace App\Services;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use App\Models\DailyReading;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Database persistence for stored days. Rows never expire. */
class ReadingStore
{
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
