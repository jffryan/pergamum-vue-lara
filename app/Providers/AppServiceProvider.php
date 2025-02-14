<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\StatisticsService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(StatisticsService::class, function ($app) {
            return new StatisticsService();
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
