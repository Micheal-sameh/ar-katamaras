<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Jobs\HeartbeatJob;
use App\Jobs\VerifyWarmedRangeJob;
use App\Mail\ReadingsWarmFailed;
use App\Services\CopticCalendarService;
use App\Services\HealthService;
use App\Services\ReadingStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
    }

    private function today(): CarbonImmutable
    {
        return app(CopticCalendarService::class)->today();
    }

    private function storeDays(int $days): void
    {
        foreach ([Language::Arabic, Language::English] as $language) {
            for ($i = 0; $i < $days; $i++) {
                app(ReadingStore::class)->save($this->sampleDto($this->today()->addDays($i), $language));
            }
        }
    }

    private function allHealthy(): void
    {
        $this->storeDays(3);
        app(HealthService::class)->recordHeartbeat('scheduler');
        app(HealthService::class)->recordHeartbeat('queue');
    }

    private function scheduled(string $needle, ?string $expression = null): ?Event
    {
        return collect(app(Schedule::class)->events())->first(
            fn (Event $e) => (str_contains((string) $e->command, $needle) || $e->description === $needle)
                && ($expression === null || $e->expression === $expression),
        );
    }

    // ---------------------------------------------------------------- health

    public function test_health_is_ok_when_everything_runs_and_days_are_stored_ahead(): void
    {
        $this->allHealthy();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.scheduler.ok', true)
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.data.ok', true);
    }

    public function test_health_fails_with_503_when_the_scheduler_never_reported(): void
    {
        $this->storeDays(3);
        app(HealthService::class)->recordHeartbeat('queue');

        $response = $this->getJson('/api/health')->assertStatus(503);

        $response->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.queue.ok', true);
        $this->assertStringContainsString('no scheduler heartbeat', $response->json('checks.scheduler.detail'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_health_fails_when_the_scheduler_or_the_queue_goes_quiet(): void
    {
        $this->allHealthy();

        $this->travel(6)->minutes(); // scheduler limit is 5 minutes, queue limit 10
        $this->getJson('/api/health')->assertStatus(503)
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.queue.ok', true);

        $this->travel(5)->minutes(); // 11 minutes without either
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.queue.ok', false);
    }

    public function test_health_fails_when_fewer_than_three_days_are_stored_ahead(): void
    {
        $this->storeDays(2);
        app(HealthService::class)->recordHeartbeat('scheduler');
        app(HealthService::class)->recordHeartbeat('queue');

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.data.ok', false);
    }

    public function test_the_heartbeat_job_proves_the_queue_worker_is_alive(): void
    {
        $this->assertFalse(app(HealthService::class)->check(['queue'])['checks']['queue']['ok']);

        (new HeartbeatJob)->handle(app(HealthService::class));

        $this->assertTrue(app(HealthService::class)->check(['queue'])['checks']['queue']['ok']);
    }

    public function test_the_scheduler_leaves_a_heartbeat_every_minute(): void
    {
        $event = $this->scheduled('readings-scheduler-heartbeat');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        $event->run($this->app);

        $this->assertTrue(app(HealthService::class)->check(['scheduler'])['checks']['scheduler']['ok']);
        $this->assertNotNull($this->scheduled('readings-queue-heartbeat', '* * * * *'));
    }

    public function test_the_health_command_sets_the_exit_code_for_docker_health_checks(): void
    {
        $this->artisan('readings:health --only=scheduler --only=queue')->assertFailed();

        app(HealthService::class)->recordHeartbeat('scheduler');
        $this->artisan('readings:health --only=scheduler')->assertSuccessful();
        $this->artisan('readings:health --only=queue')->assertFailed();
    }

    // ---------------------------------------------------------------- missed monthly run

    public function test_the_monthly_job_is_retried_every_morning_after_the_25th_until_next_month_is_stored(): void
    {
        $catchUp = $this->scheduled('readings:warm-month', '0 5 * * *');
        $this->assertNotNull($catchUp, 'daily catch-up for the monthly job is missing');
        $this->assertSame('Africa/Cairo', (string) $catchUp->timezone);

        // up to and including the 25th the normal monthly run is responsible
        $this->travelTo(CarbonImmutable::create(2026, 10, 25, 5, 0, 0, 'Africa/Cairo'));
        $this->assertFalse($catchUp->filtersPass($this->app));

        // the 26th, nothing stored (the 25th run was missed): run it
        $this->travelTo(CarbonImmutable::create(2026, 10, 26, 5, 0, 0, 'Africa/Cairo'));
        $this->assertTrue($catchUp->filtersPass($this->app));

        // once everything is stored it stays quiet
        $this->fakeKatameros();
        $this->artisan('readings:warm-month')->assertSuccessful();
        $this->assertFalse($catchUp->filtersPass($this->app));
    }

    // ---------------------------------------------------------------- weekly job also emails

    public function test_a_failing_weekly_run_emails_the_owner_once_after_the_retries(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['api.katameros.app/*' => Http::response('down', 500)]);

        $this->artisan('readings:warm --days=2 --lang=ar')->assertFailed();

        Queue::assertPushed(VerifyWarmedRangeJob::class, 1);
        Queue::assertPushed(VerifyWarmedRangeJob::class, fn ($job) => $job->jobLabel === 'Weekly pre-cache');

        (new VerifyWarmedRangeJob('2026-10-23', '2026-10-24', [3], 'Weekly pre-cache'))->handle(app(ReadingStore::class));

        Mail::assertSent(ReadingsWarmFailed::class, 1);
        Mail::assertSent(ReadingsWarmFailed::class, fn (ReadingsWarmFailed $m) => $m->job === 'Weekly pre-cache'
            && $m->hasTo('micheal.sameh@avarewase.com')
            && str_contains($m->envelope()->subject, 'Weekly pre-cache'));
    }

    public function test_a_healthy_weekly_run_sends_nothing(): void
    {
        Queue::fake();
        Mail::fake();
        $this->fakeKatameros();

        $this->artisan('readings:warm --days=2 --lang=ar')->assertSuccessful();

        Queue::assertNotPushed(VerifyWarmedRangeJob::class);
        Mail::assertNothingSent();
    }
}
