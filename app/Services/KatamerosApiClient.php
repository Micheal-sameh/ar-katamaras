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
    public function __construct(private readonly CopticCalendarService $calendar) {}

    public function fetch(CarbonImmutable $date, Language $language): DailyReadingDto
    {
        $json = $this->request($date, $language);
        $dto = $this->map($json, $date, $language);

        if ($dto->isEmpty()) {
            throw new ReadingsApiException("Katameros returned no readable text for {$date->toDateString()}.");
        }

        return $dto;
    }

    private function request(CarbonImmutable $date, Language $language): array
    {
        $query = ['languageId' => $language->value];
        if ($bibleId = config('readings.api.bible_id')) {
            $query['bibleId'] = $bibleId;
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

    private function map(array $json, CarbonImmutable $date, Language $language): DailyReadingDto
    {
        $sections = [];
        foreach ($json['sections'] as $section) {
            $subSections = [];
            foreach ($section['subSections'] ?? [] as $sub) {
                $readings = [];
                foreach ($sub['readings'] ?? [] as $reading) {
                    $readings[] = $this->mapReading($reading);
                }
                $subTitle = (string) ($sub['title'] ?? '');
                $subSections[] = new SubSectionDto(
                    ReadingType::fromTitle($subTitle),
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

    private function mapReading(array $reading): ReadingDto
    {
        $passages = [];
        foreach ($reading['passages'] ?? [] as $passage) {
            $verses = [];
            foreach ($passage['verses'] ?? [] as $verse) {
                $verses[] = new VerseDto((int) ($verse['number'] ?? 0), trim((string) ($verse['text'] ?? '')));
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
        );
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
