<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Enums\ReadingType;
use App\Exceptions\ReadingsUnavailableException;
use App\Models\DailyReading;
use App\Services\KatamerosApiClient;
use App\Services\ReadingCacheKey;
use App\Services\ReadingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class BibleAndRefreshTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    private const URL = '/api/readings/2026-10-23?lang=ar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 23, 0, 0, 0, 'Africa/Cairo');
    }

    // ---------------------------------------------------------------- chapters and reading types

    public function test_verses_keep_their_chapter(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)
            ->assertJsonPath('data.sections.0.subSections.0.readings.0.passages.0.verses.0.chapter', 3)
            ->assertJsonPath('data.sections.0.subSections.1.readings.0.passages.0.verses.0.chapter', 37);
    }

    public function test_the_psalm_and_the_gospel_are_told_apart_and_labelled(): void
    {
        $this->fakeKatameros();

        $response = $this->getJson(self::URL);

        $response->assertJsonPath('data.sections.0.subSections.1.readings.0.type', 'psalm')
            ->assertJsonPath('data.sections.0.subSections.1.readings.0.label', 'المزمور')
            ->assertJsonPath('data.sections.0.subSections.1.readings.1.type', 'gospel')
            ->assertJsonPath('data.sections.0.subSections.1.readings.1.label', 'الإنجيل');
    }

    public function test_the_psalm_and_gospel_fall_back_to_their_order_when_the_book_is_unknown(): void
    {
        $payload = $this->katamerosPayload();
        foreach ($payload['sections'][0]['subSections'][1]['readings'] as &$reading) {
            unset($reading['passages'][0]['bookId']);
        }
        unset($reading);
        $this->fakeKatameros($payload);

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);
        $readings = $dto->sections[0]->subSections[1]->readings;

        $this->assertSame([ReadingType::Psalm, ReadingType::Gospel], [$readings[0]->type, $readings[1]->type]);
    }

    public function test_a_reading_with_no_scripture_has_no_empty_passage(): void
    {
        $this->fakeKatameros(); // the Synaxarium reading has "passages": null, as in the real API

        $dto = app(KatamerosApiClient::class)->fetch($this->date(), Language::Arabic);

        $this->assertSame([], $dto->sections[1]->subSections[0]->readings[0]->passages);
    }

    // ---------------------------------------------------------------- Bible versions

    public function test_the_response_lists_the_versions_the_visitor_can_pick(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)
            ->assertJsonPath('data.bible.id', 11)
            ->assertJsonCount(2, 'data.bibles')
            ->assertJsonPath('data.bibles.0.default', true);

        $this->getJson('/api/readings/2026-10-23?lang=en')->assertJsonCount(1, 'data.bibles');
    }

    public function test_a_chosen_bible_is_requested_from_the_api_and_stored_separately(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)->assertOk();                                     // default Bible
        $this->getJson(self::URL.'&bible=3')->assertOk()->assertJsonPath('data.bible.id', 3);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'bibleId=3'));
        $this->assertSame([0, 3], DailyReading::orderBy('bible_id')->pluck('bible_id')->all());

        // each is cached under its own key, so they never mix
        $date = $this->date();
        $this->assertTrue(Cache::store('array')->has(ReadingCacheKey::for(Language::Arabic, $date)));
        $this->assertTrue(Cache::store('array')->has(ReadingCacheKey::for(Language::Arabic, $date, 3)));
        $this->getJson(self::URL.'&bible=3')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_asking_for_the_default_bible_by_id_does_not_create_a_second_copy(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL.'&bible=11')->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, DailyReading::count());
    }

    public function test_a_bible_that_is_not_on_the_allow_list_is_rejected_and_never_sent_to_the_api(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL.'&bible=999')->assertStatus(422)->assertJsonValidationErrors('bible');
        $this->getJson('/api/readings/2026-10-23?lang=en&bible=3')->assertStatus(422); // 3 is Arabic-only
        $this->getJson(self::URL.'&bible=abc')->assertStatus(422);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- refresh

    /** One fake whose answer the test can change between calls (a second Http::fake() would not override the first). */
    private function changeableApi(): \stdClass
    {
        $api = new \stdClass;
        $api->body = $this->katamerosPayload();
        $api->status = 200;
        Http::fake(fn () => Http::response($api->body, $api->status));

        return $api;
    }

    public function test_refresh_replaces_the_stored_day_with_the_corrected_text(): void
    {
        $api = $this->changeableApi();
        $service = app(ReadingService::class);
        $service->getForDate($this->date(), Language::Arabic);

        $api->body['sections'][0]['subSections'][0]['readings'][0]['passages'][0]['verses'][0]['text'] = 'النص بعد التصحيح';
        $service->refresh($this->date(), Language::Arabic);

        $this->getJson(self::URL)->assertJsonPath('data.sections.0.subSections.0.readings.0.passages.0.verses.0.text', 'النص بعد التصحيح');
        $this->assertSame(1, DailyReading::count());
    }

    public function test_a_failed_refresh_leaves_the_stored_day_untouched(): void
    {
        $api = $this->changeableApi();
        $service = app(ReadingService::class);
        $service->getForDate($this->date(), Language::Arabic);

        $api->status = 500;
        try {
            $service->refresh($this->date(), Language::Arabic);
            $this->fail('expected the refresh to fail');
        } catch (ReadingsUnavailableException) {
            // expected
        }

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.sections.0.subSections.0.readings.0.passages.0.verses.0.number', 4);
    }

    public function test_an_empty_refresh_never_overwrites_a_good_day(): void
    {
        $api = $this->changeableApi();
        $service = app(ReadingService::class);
        $service->getForDate($this->date(), Language::Arabic);

        $api->body = ['copticDate' => '13/2/1743', 'sections' => []];
        try {
            $service->refresh($this->date(), Language::Arabic);
            $this->fail('expected the refresh to fail');
        } catch (ReadingsUnavailableException) {
            // expected
        }

        $this->assertStringContainsString('أنا لبولس', json_encode(DailyReading::first()->payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_refresh_command_refetches_a_range_and_reports_failures(): void
    {
        $api = $this->changeableApi();
        $this->artisan('readings:warm --days=3 --lang=ar')->assertSuccessful();
        Http::assertSentCount(3);

        $this->artisan('readings:refresh --days=3 --lang=ar')->expectsOutputToContain('refreshed 3')->assertSuccessful();
        Http::assertSentCount(6); // stored days were fetched again

        $api->status = 500;
        $this->artisan('readings:refresh --days=3 --lang=ar')->expectsOutputToContain('failed 3')->assertFailed();
        $this->assertSame(3, DailyReading::count()); // nothing lost
    }

    public function test_the_refresh_is_scheduled_weekly_on_saturday(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'readings:refresh'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * 6', $event->expression);
        $this->assertSame('Africa/Cairo', (string) $event->timezone);
    }
}
