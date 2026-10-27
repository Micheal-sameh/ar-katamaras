<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Jobs\WarmYearJob;
use App\Services\CopticCalendarService;
use App\Services\ReadingStore;
use App\Services\ReadingWarmer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class OnDemandYearWarmTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
    }

    private function store(string $date): void
    {
        app(ReadingStore::class)->save($this->sampleDto(CarbonImmutable::parse($date, 'Africa/Cairo'), Language::Arabic));
    }

    public function test_a_day_the_server_had_to_fetch_starts_the_year_warm_up(): void
    {
        $this->fakeKatameros();

        $this->getJson('/api/readings/2026-10-25?lang=ar')->assertOk();

        Bus::assertDispatched(WarmYearJob::class, 1);
    }

    public function test_a_stored_day_does_not(): void
    {
        $this->store('2026-10-25');

        $this->getJson('/api/readings/2026-10-25?lang=ar')->assertOk();

        Bus::assertNotDispatched(WarmYearJob::class);
    }

    public function test_a_missing_past_day_does_not_because_the_warm_up_starts_today(): void
    {
        $this->fakeKatameros();

        $this->getJson('/api/readings/2026-10-01?lang=ar')->assertOk();

        Bus::assertNotDispatched(WarmYearJob::class);
    }

    public function test_a_range_with_missing_days_starts_it_and_a_complete_one_does_not(): void
    {
        Http::fake();
        $this->store('2026-10-23');
        $this->store('2026-10-24');

        $this->getJson('/api/readings/range?from=2026-10-23&days=2&lang=ar')->assertOk();
        Bus::assertNotDispatched(WarmYearJob::class);

        $this->getJson('/api/readings/range?from=2026-10-23&days=4&lang=ar')->assertOk()->assertJsonCount(2, 'data.missing');
        Bus::assertDispatched(WarmYearJob::class, 1);
    }

    public function test_requests_in_a_burst_start_it_only_once(): void
    {
        $this->fakeKatameros();

        $this->getJson('/api/readings/2026-10-25?lang=ar')->assertOk();
        $this->getJson('/api/readings/2026-10-26?lang=ar')->assertOk();
        $this->getJson('/api/readings/range?from=2026-10-27&days=3&lang=ar')->assertOk();

        Bus::assertDispatched(WarmYearJob::class, 1); // the cooldown holds the rest back
    }

    public function test_after_the_cooldown_it_can_start_again(): void
    {
        $this->fakeKatameros();
        $this->getJson('/api/readings/2026-10-25?lang=ar')->assertOk();

        $this->travel(config('readings.warm.on_demand_cooldown_minutes') + 1)->minutes();
        $this->getJson('/api/readings/2026-10-26?lang=ar')->assertOk();

        Bus::assertDispatched(WarmYearJob::class, 2);
    }

    public function test_the_job_warms_one_chunk_and_queues_the_next(): void
    {
        config(['readings.warm.year_days' => 25, 'readings.warm.year_chunk_days' => 10, 'readings.warm.sleep_seconds' => 0]);
        $this->fakeKatameros();

        (new WarmYearJob(0))->handle(app(ReadingWarmer::class), app(CopticCalendarService::class));

        $stored = 0;
        for ($i = 0; $i < 25; $i++) {
            $stored += app(ReadingStore::class)->has(CarbonImmutable::parse('2026-10-23', 'Africa/Cairo')->addDays($i), Language::Arabic) ? 1 : 0;
        }
        $this->assertSame(10, $stored); // exactly the first chunk
        Bus::assertDispatched(WarmYearJob::class, fn (WarmYearJob $job) => $job->offset === 10);
    }

    public function test_the_last_chunk_does_not_queue_another(): void
    {
        config(['readings.warm.year_days' => 25, 'readings.warm.year_chunk_days' => 10, 'readings.warm.sleep_seconds' => 0]);
        $this->fakeKatameros();

        (new WarmYearJob(20))->handle(app(ReadingWarmer::class), app(CopticCalendarService::class));

        Bus::assertNotDispatched(WarmYearJob::class);
    }
}
