<?php

namespace App\Providers;

use App\Services\CurrencyService;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('currency.service', function () {
            return new CurrencyService();
        });

        $loader = AliasLoader::getInstance();
        $loader->alias('Currency', \App\Facades\Currency::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
         if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
            $this->loadMigrationsFrom(database_path('migrations/dev'));
        }

        if ($this->app->environment('production', 'sandbox')) {
            URL::forceScheme('https');
        }
    }
}
