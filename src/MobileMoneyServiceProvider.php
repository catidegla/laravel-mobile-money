<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney;

use Illuminate\Support\ServiceProvider;

class MobileMoneyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mobile-money.php', 'mobile-money');

        $this->app->singleton(MobileMoneyManager::class, fn ($app) => new MobileMoneyManager($app));
        $this->app->alias(MobileMoneyManager::class, 'mobile-money');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'mobile-money');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mobile-money.php' => config_path('mobile-money.php'),
            ], 'mobile-money-config');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/mobile-money'),
            ], 'mobile-money-lang');
        }
    }

    public function provides(): array
    {
        return [MobileMoneyManager::class, 'mobile-money'];
    }
}
