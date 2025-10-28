<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\LLMService;
use App\Services\SerpAPIService;
use App\Services\GSCService;
use App\Services\BrandTokenService;
use App\Services\QueryNormalizationService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register services as singletons
        $this->app->singleton(LLMService::class);
        $this->app->singleton(SerpAPIService::class);
        $this->app->singleton(GSCService::class);
        $this->app->singleton(BrandTokenService::class);
        $this->app->singleton(QueryNormalizationService::class);
        $this->app->singleton(\App\Services\MonitoringService::class);
        $this->app->singleton(\App\Services\AIOService::class);
    }

    public function boot(): void
    {
        //
    }
}