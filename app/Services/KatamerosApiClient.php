<?php

namespace App\Services;

use App\Contracts\ReadingsApiClient;
use App\DTOs\CopticDateDto;
use App\DTOs\DailyReadingDto;
use App\DTOs\PassageDto;
use App\DTOs\ReadingDto;
use App\DTOs\SectionDto;
use App\DTOs\SubSectionDto;
use App\DTOs\VerseDto;
use App\Enums\CopticMonth;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Enums\ReadingType;
use App\Enums\SectionType;
use App\Exceptions\ReadingsApiException;
use App\Support\TextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The only class that knows the Katameros JSON shape.
 *
 * GET {base}/readings/gregorian/{DD-MM-YYYY}?languageId={id}[&bibleId=]
 */
class KatamerosApiClient implements ReadingsApiClient
{
    private const BOOK_PSALMS = 19;

    private const BOOK_MATTHEW = 40;

    private const BOOK_JOHN = 43;

    public function __construct(private readonly CopticCalendarService $calendar) {}

    public function fetch(CarbonImmutable $date, Language $language, int $bibleId = 0): DailyReadingDto
    {
        $json = $this->request($date, $language, $bibleId);
        $dto = $this->map($json, $date, $language, $bibleId);

        if ($dto->isEmpty()) {
            throw new ReadingsApiException("Katameros returned no readable text for {$date->toDateString()}.");
        }

        return $dto;
    }

    private function request(CarbonImmutable $date, Language $language, int $bibleId): array
    {
        $query = ['languageId' => $language->value];
        if ($bibleId > 0) {
            $query['bibleId'] = $bibleId;
        } elseif ($default = config('readings.api.bible_id')) {
            $query['bibleId'] = $default;
        }

        try {
            $response = Http::baseUrl(rtrim(config('readings.api.base_url'), '/'))
                ->acceptJson()
                ->connectTimeout(config('readings.api.connect_timeout'))
                ->timeout(config('readings.api.timeout'))
                ->retry(max(1, (int) config('readings.api.retries')), 500, throw: false)
                ->get('/readings/gregorian/'.$date->format('d-m-Y'), $query);
        } catch (ConnectionException $e) {
            throw new ReadingsApiException('Katameros API unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new ReadingsApiException("Katameros API answered HTTP {$response->status()}.");
        }

        $json = $response->json();
        if (! is_array($json) || ! isset($json['sections']) || ! is_array($json['sections'])) {
            throw new ReadingsApiException('Katameros API returned an unexpected body.');
        }

        return $json;
    }

    private function map(array $json, CarbonImmutable $date, Language $language, int $bibleId): DailyReadingDto
    {
        $sections = [];
        foreach ($json['sections'] as $section) {
            $subSections = [];
            foreach ($section['subSections'] ?? [] as $sub) {
                $subTitle = (string) ($sub['title'] ?? '');
                $subType = ReadingType::fromTitle($subTitle);
                $rawReadings = array_values(array_filter($sub['readings'] ?? [], 'is_array'));
                $readings = [];
                foreach ($rawReadings as $index => $reading) {
                    $readings[] = $this->mapReading($reading, $subType, $index, count($rawReadings));
                }
                $subSections[] = new SubSectionDto(
                    $subType,
                    $subTitle,
                    $this->nullableText($sub['introduction'] ?? null),
                    $readings,
                );
            }
            $title = (string) ($section['title'] ?? '');
            $sections[] = new SectionDto(SectionType::fromTitle($title), $title, $subSections);
        }

        return new DailyReadingDto(
            $date,
            $language,
            $this->mapCopticDate($json['copticDate'] ?? null, $date),
            $this->hoistSynaxarium($sections, $language),
            ReadingSource::Api,
            $bibleId,
        );
    }

    /**
     * The API nests the Synaxarium as a sub-section of the Liturgy ("قداس"). The app treats it as its
     * own section (Vespers, Matins, Liturgy, Synaxarium), so it is moved out here.
     *
     * @param  list<SectionDto>  $sections
     * @return list<SectionDto>
     */
    private function hoistSynaxarium(array $sections, Language $language): array
    {
        $result = [];
        $synaxarium = [];

        foreach ($sections as $section) {
            if ($section->type === SectionType::Synaxarium) {
                $synaxarium = [...$synaxarium, ...$section->subSections];

                continue;
            }

            $own = array_values(array_filter($section->subSections, fn (SubSectionDto $s) => $s->type !== ReadingType::Synaxarium));
            $moved = array_values(array_filter($section->subSections, fn (SubSectionDto $s) => $s->type === ReadingType::Synaxarium));
            $synaxarium = [...$synaxarium, ...$moved];

            if ($own !== []) {
                $result[] = new SectionDto($section->type, $section->title, $own);
            }
        }

        if ($synaxarium !== []) {
            $result[] = new SectionDto(SectionType::Synaxarium, SectionType::Synaxarium->label($language), $synaxarium);
        }

        return $result;
    }

    private function mapReading(array $reading, ReadingType $subType, int $index, int $count): ReadingDto
    {
        $passages = [];
        $bookId = null;
        foreach ($reading['passages'] ?? [] as $passage) {
            if (! is_array($passage)) {
                continue; // the API sends null for readings that have no scripture (the Synaxarium)
            }
            $bookId ??= isset($passage['bookId']) ? (int) $passage['bookId'] : null;
            $verses = [];
            foreach ($passage['verses'] ?? [] as $verse) {
                $verses[] = new VerseDto(
                    (int) ($verse['number'] ?? 0),
                    trim((string) ($verse['text'] ?? '')),
                    (int) ($verse['chapter'] ?? 0),
                );
            }
            $passages[] = new PassageDto(
                $this->nullableText($passage['bookTranslation'] ?? null),
                (string) ($passage['ref'] ?? ''),
                $verses,
            );
        }

        return new ReadingDto(
            (string) ($reading['title'] ?? ''),
            $this->nullableText($reading['introduction'] ?? null),
            $this->nullableText($reading['conclusion'] ?? null),
            $passages,
            TextNormalizer::htmlToParagraphs($reading['html'] ?? null),
            $this->readingType($subType, $bookId, $index, $count),
        );
    }

    /** The Psalm and the Gospel arrive in one sub-section; the book tells them apart (19 = Psalms, 40-43 = Gospels). */
    private function readingType(ReadingType $subType, ?int $bookId, int $index, int $count): ReadingType
    {
        if ($subType !== ReadingType::PsalmGospel) {
            return $subType;
        }
        if ($bookId === self::BOOK_PSALMS) {
            return ReadingType::Psalm;
        }
        if ($bookId !== null && $bookId >= self::BOOK_MATTHEW && $bookId <= self::BOOK_JOHN) {
            return ReadingType::Gospel;
        }

        return match (true) {
            $count === 2 && $index === 0 => ReadingType::Psalm,
            $count === 2 && $index === 1 => ReadingType::Gospel,
            default => ReadingType::PsalmGospel,
        };
    }

    /** "13/2/1743" is day/month/year. Falls back to computing it from the Gregorian date. */
    private function mapCopticDate(mixed $value, CarbonImmutable $date): CopticDateDto
    {
        if (is_string($value) && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{3,4})$#', trim($value), $m)) {
            $month = CopticMonth::tryFrom((int) $m[2]);
            if ($month !== null) {
                return new CopticDateDto((int) $m[1], $month, (int) $m[3]);
            }
        }

        return $this->calendar->fromGregorian($date);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
