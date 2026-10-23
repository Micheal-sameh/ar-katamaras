<?php

namespace Tests\Unit;

use App\Enums\CopticMonth;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Enums\ReadingType;
use App\Enums\SectionType;
use App\Exceptions\ReadingsApiException;
use App\Services\KatamerosApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class KatamerosApiClientTest extends TestCase
{
    use FakesKatameros;

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 23, 0, 0, 0, 'Africa/Cairo');
    }

    public function test_requests_the_documented_url(): void
    {
        $this->fakeKatameros();

        app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.katameros.app/readings/gregorian/23-10-2026?languageId=3');
    }

    public function test_maps_the_response_to_dtos(): void
    {
        $this->fakeKatameros();

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);

        $this->assertSame(ReadingSource::Api, $dto->source);
        $this->assertSame([13, CopticMonth::Paopi, 1743], [$dto->copticDate->day, $dto->copticDate->month, $dto->copticDate->year]);
        $this->assertSame([SectionType::Liturgy, SectionType::Synaxarium], array_map(fn ($s) => $s->type, $dto->sections));

        $liturgy = $dto->sections[0];
        $this->assertSame([ReadingType::Pauline, ReadingType::PsalmGospel], array_map(fn ($s) => $s->type, $liturgy->subSections));

        $reading = $liturgy->subSections[0]->readings[0];
        $this->assertSame('نعمة الله الآب تكون مع جميعكم. آمين.', $reading->conclusion);
        $passage = $reading->passages[0];
        $this->assertSame('١ كورنثوس ٣: ٤–٢٣', $passage->ref);
        $this->assertSame('كورنثوس الأولى', $passage->bookTranslation);
        $this->assertSame([4, 5], array_map(fn ($v) => $v->number, $passage->verses));
    }

    public function test_the_synaxarium_nested_in_the_liturgy_becomes_its_own_section(): void
    {
        $this->fakeKatameros();

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);

        $liturgyTypes = array_map(fn ($s) => $s->type, $dto->sections[0]->subSections);
        $this->assertNotContains(ReadingType::Synaxarium, $liturgyTypes);
        $this->assertSame('السنكسار', $dto->sections[1]->title);
        $this->assertSame(ReadingType::Synaxarium, $dto->sections[1]->subSections[0]->type);
    }

    public function test_synaxarium_html_becomes_plain_text_paragraphs(): void
    {
        $this->fakeKatameros();

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);
        $paragraphs = $dto->sections[1]->subSections[0]->readings[0]->paragraphs;

        $this->assertCount(2, $paragraphs);
        $this->assertSame('في مثل هذا اليوم تنيّح القديس.', $paragraphs[0]);
        $this->assertStringStartsWith('بركة صلواته تكون معنا.', $paragraphs[1]);
        $this->assertStringEndsWith('آمين.', $paragraphs[1]);
        $this->assertStringNotContainsString('<', implode('', $paragraphs));
        $this->assertStringNotContainsString('alert', implode('', $paragraphs));
    }

    public function test_falls_back_to_the_calendar_when_the_coptic_date_is_missing(): void
    {
        $this->fakeKatameros($this->katamerosPayload('not a date'));

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);

        $this->assertSame('13/2/1743', "{$dto->copticDate->day}/{$dto->copticDate->month->value}/{$dto->copticDate->year}");
    }

    public function test_maps_section_titles_in_any_language(): void
    {
        $this->assertSame(SectionType::Vespers, SectionType::fromTitle('العشية'));
        $this->assertSame(SectionType::Matins, SectionType::fromTitle('باكر'));
        $this->assertSame(SectionType::Liturgy, SectionType::fromTitle('Divine Liturgy'));
        $this->assertSame(SectionType::Synaxarium, SectionType::fromTitle('Synaxaire'));
        $this->assertSame(ReadingType::Praxis, ReadingType::fromTitle('الابركسيس'));
        $this->assertSame(ReadingType::Praxis, ReadingType::fromTitle('الإبركسيس'));
        $this->assertSame(ReadingType::Catholic, ReadingType::fromTitle('الكاثوليكون'));
        $this->assertSame(ReadingType::PsalmGospel, ReadingType::fromTitle('المزمور والإنجيل'));
        $this->assertSame(ReadingType::Gospel, ReadingType::fromTitle('الإنجيل'));
        $this->assertSame(ReadingType::Other, ReadingType::fromTitle('???'));
    }

    public function test_http_errors_throw(): void
    {
        Http::fake(['api.katameros.app/*' => Http::response('boom', 500)]);

        $this->expectException(ReadingsApiException::class);
        app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);
    }

    public function test_a_response_without_readable_text_throws(): void
    {
        $this->fakeKatameros(['copticDate' => '13/2/1743', 'sections' => []]);

        $this->expectException(ReadingsApiException::class);
        app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);
    }

    public function test_connection_failures_throw_the_api_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(ReadingsApiException::class);
        app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);
    }
}
