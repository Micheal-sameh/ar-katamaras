<?php

namespace Tests\Feature;

use App\DTOs\CopticDateDto;
use App\Enums\CopticMonth;
use App\Enums\Language;
use App\Models\DailyReading;
use App\Services\CopticCalendarService;
use App\Services\PptImportService;
use App\Services\ReadingCacheKey;
use App\Services\ReadingService;
use App\Services\ReadingStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class ReadingEndpointTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    private const URL = '/api/readings/2026-10-23?lang=ar';

    public function test_returns_the_day_in_the_documented_shape(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-23')
            ->assertJsonPath('data.language', 'ar')
            ->assertHeader('X-Readings-Source', 'api')
            ->assertJsonPath('data.copticDate.monthName', 'بابه')
            ->assertJsonPath('data.sections.0.type', 'liturgy')
            ->assertJsonPath('data.sections.0.subSections.0.type', 'pauline')
            ->assertJsonPath('data.sections.0.subSections.0.readings.0.passages.0.verses.0.number', 4);
    }

    public function test_second_request_for_the_same_date_makes_zero_api_calls(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)->assertOk();
        Http::assertSentCount(1);

        $second = $this->getJson(self::URL)->assertOk();

        Http::assertSentCount(1); // still one: the second answer came from the cache
        $second->assertHeader('X-Readings-Source', 'cache');
        $this->assertSame(1, DailyReading::count());
    }

    public function test_falls_back_to_the_database_when_the_cache_is_empty(): void
    {
        $this->fakeKatameros();
        $this->getJson(self::URL)->assertOk();

        Cache::store('array')->flush();

        $this->getJson(self::URL)->assertOk()->assertHeader('X-Readings-Source', 'api'); // source as stored
        Http::assertSentCount(1);
        $this->assertTrue(Cache::store('array')->has(ReadingCacheKey::for(Language::Arabic, CarbonImmutable::parse('2026-10-23'))));
    }

    public function test_each_language_is_stored_separately(): void
    {
        $this->fakeKatameros();

        $this->getJson('/api/readings/2026-10-23?lang=ar')->assertOk();
        $this->getJson('/api/readings/2026-10-23?lang=en')->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'languageId=2'));
        $this->assertSame(2, DailyReading::count());
    }

    public function test_returns_503_with_a_friendly_message_when_the_api_fails_and_nothing_is_stored(): void
    {
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);

        $response = $this->getJson(self::URL)
            ->assertStatus(503)
            ->assertJsonPath('code', 'readings_unavailable')
            ->assertHeader('Retry-After');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_error_or_empty_response_is_never_stored_or_cached(): void
    {
        Http::fake(['api.katameros.app/*' => Http::response(['copticDate' => '13/2/1743', 'sections' => []])]);

        $this->getJson(self::URL)->assertStatus(503);

        $this->assertSame(0, DailyReading::count());
        $this->assertFalse(Cache::store('array')->has(ReadingCacheKey::for(Language::Arabic, CarbonImmutable::parse('2026-10-23'))));
    }

    public function test_keeps_serving_the_stored_copy_when_the_api_is_down(): void
    {
        $this->fakeKatameros();
        $this->getJson(self::URL)->assertOk();

        Cache::store('array')->flush();
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);

        $this->getJson(self::URL)->assertOk();
    }

    public function test_sends_cache_headers_and_honours_if_none_match(): void
    {
        $this->fakeKatameros();

        $first = $this->getJson(self::URL)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('max-age=', $first->headers->get('Cache-Control'));

        $this->getJson(self::URL, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_validates_date_and_language(): void
    {
        $this->getJson('/api/readings/2026-10-23?lang=xx')->assertStatus(422)->assertJsonValidationErrors('lang');
        $this->getJson('/api/readings/2026-13-45')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/readings/not-a-date')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_request_that_waits_on_the_lock_gets_the_stored_day_without_calling_the_api(): void
    {
        $this->fakeKatameros();
        $date = CarbonImmutable::create(2026, 10, 23, 0, 0, 0, 'Africa/Cairo');
        $language = Language::Arabic;

        // Another worker already holds the lock for this date (it is fetching it).
        $otherWorker = Cache::store('array')->getStore()->lock(ReadingCacheKey::lock($language, $date), 30);
        $this->assertTrue($otherWorker->get());

        // While this request waits, the other worker finishes: it saves the day and releases the lock.
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($otherWorker, $date, $language) {
            app(ReadingStore::class)->save($this->sampleDto($date, $language, 'saved by the other worker'));
            $otherWorker->release();
        });

        $reading = app(ReadingService::class)->getForDate($date, $language);

        Http::assertNothingSent();
        $this->assertSame('saved by the other worker', $reading->sections[0]->subSections[0]->readings[0]->passages[0]->verses[0]->text);
    }

    public function test_the_lock_is_released_after_a_fetch(): void
    {
        $this->fakeKatameros();
        $date = CarbonImmutable::create(2026, 10, 23, 0, 0, 0, 'Africa/Cairo');

        app(ReadingService::class)->getForDate($date, Language::Arabic);

        $lock = Cache::store('array')->getStore()->lock(ReadingCacheKey::lock(Language::Arabic, $date), 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_ppt_override_invalidates_the_cached_date(): void
    {
        $this->fakeKatameros();
        $first = $this->getJson(self::URL)->assertOk();
        $this->assertStringContainsString('لأنه متى قال واحد', json_encode($first->json(), JSON_UNESCAPED_UNICODE));
        Http::assertSentCount(1);

        // The church's own text for the same day arrives as a PPT.
        $pptx = $this->makePptx('2026-10-23_ar.pptx', [
            ['البولس', '1 نص الكنيسة الأول', '2 نص الكنيسة الثاني'],
        ]);
        app(PptImportService::class)->import($pptx);

        $after = $this->getJson(self::URL)->assertOk();

        $after->assertHeader('X-Readings-Source', 'ppt');
        $after->assertJsonPath('data.sections.0.subSections.0.type', 'pauline');
        $after->assertJsonPath('data.sections.0.subSections.0.readings.0.passages.0.verses.0.text', 'نص الكنيسة الأول');
        $this->assertStringNotContainsString('لأنه متى قال واحد', json_encode($after->json(), JSON_UNESCAPED_UNICODE));
        Http::assertSentCount(1); // the override replaced the API copy without another API call
    }

    public function test_re_importing_an_unchanged_ppt_leaves_the_cache_alone(): void
    {
        $this->fakeKatameros();
        $pptx = $this->makePptx('2026-10-23_ar.pptx', [['البولس', '1 نص']]);
        $importer = app(PptImportService::class);
        $importer->import($pptx);
        $this->getJson(self::URL)->assertOk();
        $key = ReadingCacheKey::for(Language::Arabic, CarbonImmutable::parse('2026-10-23'));
        $this->assertTrue(Cache::store('array')->has($key));

        $importer->import($pptx); // identical file

        $this->assertTrue(Cache::store('array')->has($key));
    }

    public function test_a_ppt_is_used_instead_of_the_api_for_a_day_that_has_no_stored_copy(): void
    {
        $this->fakeKatameros();
        app(PptImportService::class)->import($this->makePptx('2026-10-24_ar.pptx', [['السنكسار', 'فقرة أولى', 'فقرة ثانية']]));

        $this->getJson('/api/readings/2026-10-24?lang=ar')
            ->assertOk()
            ->assertHeader('X-Readings-Source', 'ppt')
            ->assertJsonPath('data.sections.0.type', 'synaxarium')
            ->assertJsonPath('data.sections.0.subSections.0.readings.0.paragraphs.1', 'فقرة ثانية');
        Http::assertNothingSent();
    }

    public function test_a_coptic_day_override_applies_to_every_year(): void
    {
        $this->fakeKatameros();
        app(PptImportService::class)->import($this->makePptx('coptic-02-13_ar.pptx', [['البولس', '1 نص يتكرر كل سنة']]));

        // 2026-10-23 is 13 Paopi 1743; a year later the Coptic day is the same even though the Gregorian date moves.
        $this->getJson(self::URL)->assertOk()->assertHeader('X-Readings-Source', 'ppt');
        $nextYear = app(CopticCalendarService::class)->toGregorian(new CopticDateDto(13, CopticMonth::Paopi, 1744));
        $this->getJson('/api/readings/'.$nextYear->toDateString().'?lang=ar')->assertOk()->assertHeader('X-Readings-Source', 'ppt');
        Http::assertNothingSent();
    }

    public function test_ppt_import_rejects_files_it_cannot_place(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PptImportService::class)->import($this->makePptx('mystery.pptx', [['البولس', '1 نص']]));
    }
}
