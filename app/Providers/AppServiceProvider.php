<?php

namespace App\Providers;

use App\Statistics\MetricRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(MetricRegistry::class, function ($app) {
            return new MetricRegistry(array_map(
                fn (string $metric) => $app->make($metric),
                config('statistics.metrics', [])
            ));
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
