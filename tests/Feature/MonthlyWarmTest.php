<?php

namespace Tests\Feature;

use App\Jobs\RetryWarmDayJob;
use App\Jobs\VerifyWarmedRangeJob;
use App\Mail\ReadingsWarmFailed;
use App\Models\DailyReading;
use App\Services\ReadingStore;
use App\Services\ReadingWarmer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class MonthlyWarmTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    private const ALERT_EMAIL = 'micheal.sameh@avarewase.com';

    private function travelToDay(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 03:30', 'Africa/Cairo'));
    }

    public function test_on_the_25th_it_stores_the_rest_of_this_month_and_the_whole_next_month(): void
    {
        $this->fakeKatameros();
        $this->travelToDay('2026-10-25');

        $this->artisan('readings:warm-month --lang=ar')->assertSuccessful();

        // 25..31 October (7 days) + 30 days of November
        $this->assertSame(37, DailyReading::count());
        $dates = DailyReading::orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10));
        $this->assertSame('2026-10-25', $dates->first());
        $this->assertSame('2026-11-30', $dates->last());
        Http::assertSentCount(37);
    }

    public function test_the_window_rolls_over_the_year_end(): void
    {
        $this->travelToDay('2026-12-25');

        $window = app(ReadingWarmer::class)->monthWindow(1);

        $this->assertSame('2026-12-25', $window['from']->toDateString());
        $this->assertSame('2027-01-31', $window['to']->toDateString());
    }

    public function test_the_window_does_not_overflow_into_a_later_month(): void
    {
        $this->travelToDay('2026-01-30'); // February has no 30th: must still end on 28 February

        $this->assertSame('2026-02-28', app(ReadingWarmer::class)->monthWindow(1)['to']->toDateString());
    }

    public function test_already_stored_days_are_skipped(): void
    {
        $this->fakeKatameros();
        $this->travelToDay('2026-10-25');
        $this->artisan('readings:warm-month --lang=ar')->assertSuccessful();
        Http::assertSentCount(37);

        $this->artisan('readings:warm-month --lang=ar')->expectsOutputToContain('skipped 37')->assertSuccessful();

        Http::assertSentCount(37);
    }

    public function test_no_email_when_everything_is_stored(): void
    {
        Mail::fake();
        Queue::fake();
        $this->fakeKatameros();
        $this->travelToDay('2026-10-25');

        $this->artisan('readings:warm-month --lang=ar')->assertSuccessful();

        Queue::assertNotPushed(VerifyWarmedRangeJob::class);
        Mail::assertNothingSent();
    }

    public function test_failed_days_are_retried_and_one_verification_is_scheduled(): void
    {
        Queue::fake();
        $this->travelToDay('2026-10-25');
        Http::fake(function ($request) {
            return str_contains($request->url(), '/gregorian/10-11-2026')
                ? Http::response('server error', 500)
                : Http::response($this->katamerosPayload());
        });

        $this->artisan('readings:warm-month --lang=ar')->assertSuccessful();

        $this->assertSame(36, DailyReading::count());
        Queue::assertPushed(RetryWarmDayJob::class, 1);
        Queue::assertPushed(VerifyWarmedRangeJob::class, 1); // one, however many days failed
        Queue::assertPushed(VerifyWarmedRangeJob::class, fn ($job) => $job->from === '2026-10-25' && $job->to === '2026-11-30');
    }

    public function test_the_verification_emails_the_owner_once_when_days_are_still_missing(): void
    {
        Mail::fake();
        $this->travelToDay('2026-10-25');
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);
        Queue::fake();
        $this->artisan('readings:warm-month --lang=ar --months=0')->assertSuccessful(); // 25..31 Oct, all fail

        (new VerifyWarmedRangeJob('2026-10-25', '2026-10-31', [3]))->handle(app(ReadingStore::class));

        Mail::assertSent(ReadingsWarmFailed::class, 1);
        Mail::assertSent(ReadingsWarmFailed::class, function (ReadingsWarmFailed $mail) {
            return $mail->hasTo(self::ALERT_EMAIL)
                && count($mail->missing) === 7
                && in_array('ar:2026-10-25', $mail->missing, true)
                && $mail->errors !== []
                && str_contains($mail->render(), 'ar:2026-10-31');
        });
    }

    public function test_the_verification_stays_quiet_when_the_retries_filled_the_gap(): void
    {
        Mail::fake();
        $this->travelToDay('2026-10-25');
        $this->fakeKatameros();
        $this->artisan('readings:warm-month --lang=ar --months=0')->assertSuccessful();

        (new VerifyWarmedRangeJob('2026-10-25', '2026-10-31', [3]))->handle(app(ReadingStore::class));

        Mail::assertNothingSent();
    }

    public function test_a_crash_emails_the_owner_immediately(): void
    {
        Mail::fake();
        $this->travelToDay('2026-10-25');
        $this->mock(ReadingWarmer::class, function ($mock) {
            $mock->shouldReceive('monthWindow')->andReturn([
                'from' => CarbonImmutable::parse('2026-10-25'),
                'to' => CarbonImmutable::parse('2026-11-30'),
            ]);
            $mock->shouldReceive('warmRange')->andThrow(new \RuntimeException('database is locked'));
        });

        $this->artisan('readings:warm-month')->assertFailed();

        Mail::assertSent(ReadingsWarmFailed::class, fn (ReadingsWarmFailed $m) => $m->hasTo(self::ALERT_EMAIL)
            && $m->reason === 'the job crashed'
            && str_contains($m->errors[0], 'database is locked'));
    }

    public function test_the_alert_address_is_configurable(): void
    {
        $this->assertSame(self::ALERT_EMAIL, config('readings.alerts.email'));
    }

    public function test_is_scheduled_for_the_25th_at_03_30_cairo_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'readings:warm-month'));

        $this->assertNotNull($event, 'monthly event is missing');
        $this->assertSame('30 3 25 * *', $event->expression);
        $this->assertSame('Africa/Cairo', (string) $event->timezone);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
}
