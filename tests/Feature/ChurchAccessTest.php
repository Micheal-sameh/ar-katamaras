<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Services\ReadingStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

class ChurchAccessTest extends TestCase
{
    use FakesKatameros;
    use RefreshDatabase;

    private const DAY = '/api/readings/2026-10-23?lang=ar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12, 0, 0, 'Africa/Cairo'));
        config(['readings.access.code' => 'saint-mark']);
    }

    public function test_without_the_code_the_readings_are_refused_and_nothing_is_fetched(): void
    {
        Http::fake();

        $this->getJson(self::DAY)->assertStatus(401)->assertJsonPath('code', 'church_code_required');
        $this->getJson('/api/readings/range?from=2026-10-23&days=3&lang=ar')->assertStatus(401);
        $this->getJson('/api/readings/access')->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_with_the_right_code_everything_works(): void
    {
        $this->fakeKatameros();
        $headers = ['X-Church-Code' => 'saint-mark'];

        $this->getJson(self::DAY, $headers)->assertOk()->assertJsonPath('data.date', '2026-10-23');
        $this->getJson('/api/readings/range?from=2026-10-23&days=3&lang=ar', $headers)->assertOk();
        $this->getJson('/api/readings/access', $headers)->assertNoContent();
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->getJson(self::DAY, ['X-Church-Code' => 'guess'])->assertStatus(401);
    }

    public function test_without_a_configured_code_the_api_stays_open(): void
    {
        config(['readings.access.code' => '']);
        $this->fakeKatameros();

        $this->getJson(self::DAY)->assertOk();
    }

    public function test_the_health_check_stays_open_for_the_uptime_monitor(): void
    {
        $this->assertNotSame(401, $this->getJson('/api/health')->getStatusCode());
    }

    public function test_guessing_is_limited_per_ip(): void
    {
        config(['readings.access.max_failures_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->getJson(self::DAY, ['X-Church-Code' => "guess{$i}"])->assertStatus(401);
        }

        $this->getJson(self::DAY, ['X-Church-Code' => 'guess4'])->assertStatus(429)->assertJsonPath('code', 'church_code_blocked')->assertHeader('Retry-After');

        // The right code is never blocked: someone guessing on the church's shared network cannot lock the members out.
        $this->fakeKatameros();
        $this->getJson(self::DAY, ['X-Church-Code' => 'saint-mark'])->assertOk();
    }

    public function test_a_missing_code_is_not_counted_as_a_wrong_guess(): void
    {
        config(['readings.access.max_failures_per_minute' => 2]);

        $this->getJson(self::DAY)->assertStatus(401);
        $this->getJson(self::DAY)->assertStatus(401);
        $this->getJson(self::DAY)->assertStatus(401);
        $this->getJson(self::DAY)->assertStatus(401);

        $this->fakeKatameros();
        $this->getJson(self::DAY, ['X-Church-Code' => 'saint-mark'])->assertOk();
    }

    public function test_answers_are_private_so_a_shared_cache_cannot_hand_them_to_outsiders(): void
    {
        app(ReadingStore::class)->save($this->sampleDto(CarbonImmutable::parse('2026-10-23', 'Africa/Cairo'), Language::Arabic));

        $response = $this->getJson(self::DAY, ['X-Church-Code' => 'saint-mark'])->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('X-Church-Code', (string) $response->headers->get('Vary'));
    }
}
