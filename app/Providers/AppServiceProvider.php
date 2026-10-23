<?php

namespace App\Providers;

use App\Contracts\ReadingsApiClient;
use App\Services\KatamerosApiClient;
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
        if(config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
