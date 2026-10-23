<?php

namespace Tests\Feature;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Exceptions\ReadingsUnavailableException;
use App\Jobs\RetryWarmDayJob;
use App\Models\DailyReading;
use App\Services\CopticCalendarService;
use App\Services\ReadingService;
use App\Services\ReadingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class WarmReadingsTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    private function today()
    {
        return app(CopticCalendarService::class)->today();
    }

    public function test_stores_the_next_seven_days(): void
    {
        $this->fakeKatameros();

        $this->artisan('readings:warm --lang=ar')->assertSuccessful();

        $this->assertSame(7, DailyReading::count());
        Http::assertSentCount(7);
        $this->assertTrue(DailyReading::whereDate('date', $this->today()->toDateString())->exists());
        $this->assertTrue(DailyReading::whereDate('date', $this->today()->addDays(6)->toDateString())->exists());
        $this->assertFalse(DailyReading::whereDate('date', $this->today()->addDays(7)->toDateString())->exists());
    }

    public function test_the_number_of_days_is_configurable(): void
    {
        $this->fakeKatameros();

        $this->artisan('readings:warm --days=3 --lang=ar')->assertSuccessful();

        $this->assertSame(3, DailyReading::count());
    }

    public function test_covers_every_configured_language_by_default(): void
    {
        $this->fakeKatameros();

        $this->artisan('readings:warm --days=2')->assertSuccessful();

        $this->assertSame(4, DailyReading::count()); // 2 days x (Arabic, English)
    }

    public function test_skips_stored_dates_and_calls_the_api_only_for_missing_ones(): void
    {
        $this->fakeKatameros();
        $this->artisan('readings:warm --lang=ar')->assertSuccessful();
        Http::assertSentCount(7);

        $this->artisan('readings:warm --lang=ar')->expectsOutputToContain('skipped 7')->assertSuccessful();
        Http::assertSentCount(7); // nothing new

        DailyReading::whereDate('date', $this->today()->addDays(3)->toDateString())->delete();
        Cache::store('array')->flush();
        $this->artisan('readings:warm --lang=ar')->assertSuccessful();
        Http::assertSentCount(8); // only the gap was fetched
    }

    public function test_a_failed_day_is_retried_without_breaking_the_others(): void
    {
        Queue::fake();
        $failing = $this->today()->addDays(2);
        $apiDown = true;
        Http::fake(function ($request) use ($failing, &$apiDown) {
            if ($apiDown && str_contains($request->url(), '/gregorian/'.$failing->format('d-m-Y'))) {
                return Http::response('server error', 500);
            }

            return Http::response($this->katamerosPayload());
        });

        $this->artisan('readings:warm --lang=ar')->assertFailed(); // incomplete window

        $this->assertSame(6, DailyReading::count());
        $this->assertFalse(DailyReading::whereDate('date', $failing->toDateString())->exists());
        Queue::assertPushed(RetryWarmDayJob::class, 1);
        Queue::assertPushed(RetryWarmDayJob::class, fn ($job) => $job->date === $failing->toDateString() && $job->languageId === Language::Arabic->value);

        // The API recovers; the queued retry stores the missing day.
        $apiDown = false;
        (new RetryWarmDayJob($failing->toDateString(), Language::Arabic->value))->handle(app(ReadingService::class));

        $this->assertSame(7, DailyReading::count());
    }

    public function test_the_retry_job_uses_the_one_five_fifteen_minute_schedule(): void
    {
        $job = new RetryWarmDayJob('2026-10-23', 3);

        $this->assertSame(3, $job->tries);
        $this->assertSame([300, 900], $job->backoff());
        $this->assertSame([60, 300, 900], config('readings.warm.retry_delays'));
    }

    public function test_the_retry_job_fails_while_the_api_is_still_down(): void
    {
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);

        $this->expectException(ReadingsUnavailableException::class);
        (new RetryWarmDayJob($this->today()->toDateString(), 3))->handle(app(ReadingService::class));
    }

    public function test_never_overwrites_a_good_stored_day_with_an_empty_one(): void
    {
        $date = $this->today();
        $store = app(ReadingStore::class);
        $store->save($this->sampleDto($date, Language::Arabic, 'good text'));

        $empty = new DailyReadingDto($date, Language::Arabic, $this->sampleDto($date, Language::Arabic)->copticDate, [], ReadingSource::Api);

        $this->assertFalse($store->save($empty));
        $stored = $store->find($date, Language::Arabic);
        $this->assertSame('good text', $stored->sections[0]->subSections[0]->readings[0]->passages[0]->verses[0]->text);
    }

    public function test_alerts_when_fewer_than_three_days_are_stored_ahead(): void
    {
        Log::spy();
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);
        Queue::fake();
        app(ReadingStore::class)->save($this->sampleDto($this->today(), Language::Arabic));

        $this->artisan('readings:warm --days=3 --lang=ar')->assertFailed();

        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'fewer than'))->once();
    }

    public function test_logs_how_many_days_are_stored_ahead_when_healthy(): void
    {
        Log::spy();
        $this->fakeKatameros();

        $this->artisan('readings:warm --lang=ar')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => str_contains($message, 'days stored ahead') && ($context['days_ahead'] ?? null) === 7)->once();
    }

    public function test_status_lists_the_stored_range_per_language(): void
    {
        $this->fakeKatameros();
        $this->artisan('readings:warm --days=3 --lang=ar')->assertSuccessful();

        $this->artisan('readings:status')
            ->expectsTable(
                ['Language', 'First stored', 'Last stored', 'Days', 'Consecutive from today'],
                [
                    ['ar', $this->today()->toDateString(), $this->today()->addDays(2)->toDateString(), 3, 3],
                    ['en', '-', '-', 0, 0],
                ],
            )
            ->assertSuccessful();
    }

    public function test_rejects_an_unknown_language(): void
    {
        $this->artisan('readings:warm --lang=xx')->assertExitCode(2);
    }

    public function test_is_scheduled_weekly_on_tuesday_at_three_cairo_time(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'readings:warm'));

        $weekly = $events->first(fn ($e) => $e->expression === '0 3 * * 2');

        $this->assertNotNull($weekly, 'weekly Tuesday 03:00 event is missing');
        $this->assertSame('Africa/Cairo', (string) $weekly->timezone);
        $this->assertTrue($weekly->onOneServer);
        $this->assertTrue($weekly->withoutOverlapping);
        $this->assertGreaterThanOrEqual(2, $events->count(), 'daily catch-up event is missing');
    }
}
