<?php

namespace Tests;

use App\Jobs\WarmYearJob;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The queue is "sync" in tests, so the on-demand year warm-up would really run (about 700 API calls).
        // Only this job is faked; every other job still runs. Tests can assert it was dispatched.
        Bus::fake([WarmYearJob::class]);
    }
}
