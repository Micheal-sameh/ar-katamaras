<?php

namespace App\DTOs;

use App\Enums\Language;
use App\Enums\ReadingSource;
use Carbon\CarbonImmutable;

final readonly class DailyReadingDto
{
    /**
     * @param  list<SectionDto>  $sections
     * @param  int  $bibleId  0 = the language's default Bible
     */
    public function __construct(
        public CarbonImmutable $date,
        public Language $language,
        public CopticDateDto $copticDate,
        public array $sections,
        public ReadingSource $source,
        public int $bibleId = 0,
    ) {}

    /** True when there is no readable text at all. Such a day is never stored or cached. */
    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->hasContent()) {
                return false;
            }
        }

        return true;
    }

    public function withSource(ReadingSource $source): self
    {
        return new self($this->date, $this->language, $this->copticDate, $this->sections, $source, $this->bibleId);
    }

    /** Payload stored in daily_readings.payload / the cache. The source and Bible are stored separately. */
    public function toPayload(): array
    {
        return [
            'copticDate' => $this->copticDate->toArray(),
            'sections' => array_map(fn (SectionDto $s) => $s->toArray(), $this->sections),
        ];
    }

    public static function fromPayload(
        CarbonImmutable $date,
        Language $language,
        ReadingSource $source,
        array $payload,
        int $bibleId = 0,
    ): self {
        return new self(
            $date,
            $language,
            CopticDateDto::fromArray($payload['copticDate']),
            array_map(SectionDto::fromArray(...), $payload['sections'] ?? []),
            $source,
            $bibleId,
        );
    }
}
