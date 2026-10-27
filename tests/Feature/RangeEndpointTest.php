<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Services\ReadingStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class RangeEndpointTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
    }

    private function store(string $from, int $days): void
    {
        $date = CarbonImmutable::parse($from, 'Africa/Cairo');
        for ($i = 0; $i < $days; $i++) {
            app(ReadingStore::class)->save($this->sampleDto($date->addDays($i), Language::Arabic));
        }
    }

    public function test_it_returns_the_stored_days_in_one_request_and_lists_the_missing_ones(): void
    {
        Http::fake();
        $this->store('2026-10-23', 3); // 23, 24, 25 stored; 26 is not

        $response = $this->getJson('/api/readings/range?from=2026-10-23&days=4&lang=ar')->assertOk();

        $response->assertJsonPath('data.from', '2026-10-23')
            ->assertJsonCount(3, 'data.days')
            ->assertJsonPath('data.days.0.date', '2026-10-23')
            ->assertJsonPath('data.days.2.date', '2026-10-25')
            ->assertJsonPath('data.days.0.language', 'ar')
            ->assertJsonPath('data.missing', ['2026-10-26']);
        Http::assertNothingSent(); // never fetches from the upstream API
    }

    public function test_it_refuses_a_range_that_is_too_long_or_outside_the_window(): void
    {
        $this->getJson('/api/readings/range?from=2026-10-23&days=999&lang=ar')->assertStatus(422)->assertJsonValidationErrors('days');
        $this->getJson('/api/readings/range?from=2030-01-01&days=14&lang=ar')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/readings/range?days=14&lang=ar')->assertStatus(422);
        $this->getJson('/api/readings/range?from=2026-10-23&days=14&lang=xx')->assertStatus(422)->assertJsonValidationErrors('lang');
    }

    public function test_the_year_warm_is_scheduled_weekly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('--days='.config('readings.warm.year_days'))->assertSuccessful();
    }
}
