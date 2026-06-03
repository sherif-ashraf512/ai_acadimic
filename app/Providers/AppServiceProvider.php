<?php

namespace App\Providers;

use App\Services\GeminiService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind GeminiService as a singleton; reads the API key once from config.
        $this->app->singleton(GeminiService::class, function () {
            return new GeminiService(
                apiKey: config('services.gemini.key', env('GEMINI_API_KEY'))
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
