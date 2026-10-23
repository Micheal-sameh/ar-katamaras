<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Models\DailyReading;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
    }

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

    public function test_dates_outside_the_public_window_are_rejected_without_calling_the_api(): void
    {
        $this->fakeKatameros();

        $this->getJson('/api/readings/2024-01-01?lang=ar')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/readings/2030-01-01?lang=ar')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/readings/2026-10-23?lang=ar')->assertOk(); // today is inside the window
        $this->getJson('/api/readings/2027-10-23?lang=ar')->assertOk(); // edge of the default 366 day window

        Http::assertSentCount(2);
    }

    public function test_the_window_is_configurable(): void
    {
        $this->fakeKatameros();
        config(['readings.http.past_days' => 10, 'readings.http.future_days' => 10]);

        $this->getJson('/api/readings/2026-10-12?lang=ar')->assertStatus(422);
        $this->getJson('/api/readings/2026-10-13?lang=ar')->assertOk();
        $this->getJson('/api/readings/2026-11-02?lang=ar')->assertOk();
        $this->getJson('/api/readings/2026-11-03?lang=ar')->assertStatus(422);
    }

    public function test_each_client_ip_is_rate_limited(): void
    {
        $this->fakeKatameros();
        config(['readings.http.throttle_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->getJson(self::URL)->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->getJson(self::URL)->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])->getJson(self::URL)->assertOk(); // another visitor is unaffected
    }

    public function test_security_headers_are_present(): void
    {
        $this->fakeKatameros();

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_hsts_is_sent_over_https_only(): void
    {
        $this->fakeKatameros();

        $this->getJson(self::URL)->assertHeaderMissing('Strict-Transport-Security');
        $this->withServerVariables(['HTTPS' => 'on'])->getJson('https://localhost'.self::URL)->assertHeader('Strict-Transport-Security');
    }

    public function test_cors_allows_get_from_other_origins_and_exposes_the_etag(): void
    {
        $this->fakeKatameros();

        $preflight = $this->call('OPTIONS', '/api/readings/2026-10-23', [], [], [], [
            'HTTP_ORIGIN' => 'http://tauri.localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertSame(204, $preflight->getStatusCode());
        $this->assertSame('*', $preflight->headers->get('Access-Control-Allow-Origin'));

        $actual = $this->getJson(self::URL, ['Origin' => 'http://tauri.localhost'])->assertOk();
        $this->assertStringContainsString('ETag', $actual->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_cors_rejects_post_from_other_origins(): void
    {
        $preflight = $this->call('OPTIONS', '/api/readings/2026-10-23', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertStringNotContainsString('POST', (string) $preflight->headers->get('Access-Control-Allow-Methods'));
    }
}
