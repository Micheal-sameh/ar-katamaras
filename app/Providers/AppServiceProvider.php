<?php

namespace App\Providers;

use App\Contracts\ReadingsApiClient;
use App\Services\KatamerosApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The only binding to change when swapping the readings source (or mocking it in tests).
        $this->app->bind(ReadingsApiClient::class, KatamerosApiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        $this->trustConfiguredProxies();

        RateLimiter::for('readings', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('readings.http.throttle_per_minute')),
        )->by($request->ip()));
    }

    private function trustConfiguredProxies(): void
    {
        $proxies = trim((string) config('readings.http.trusted_proxies'));
        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
    }
}
