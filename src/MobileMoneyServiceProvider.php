<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney;

use Catidegla\MobileMoney\Console\ReconcilePendingPayments;
use Catidegla\MobileMoney\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'mobile-money');

        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePendingPayments::class]);

            $this->publishes([
                __DIR__.'/../config/mobile-money.php' => config_path('mobile-money.php'),
            ], 'mobile-money-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mobile-money-migrations');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/mobile-money'),
            ], 'mobile-money-lang');
        }
    }

    /**
     * Callbacks arrive from outside the session, so the route uses the api
     * middleware group by default. Putting it behind web middleware would mean
     * CSRF rejecting every provider notification.
     */
    private function registerWebhookRoute(): void
    {
        if (! config('mobile-money.webhooks.enabled', true)) {
            return;
        }

        Route::middleware(config('mobile-money.webhooks.middleware', ['api']))
            ->post(
                config('mobile-money.webhooks.path', 'mobile-money/webhook/{provider}'),
                WebhookController::class,
            )
            ->name('mobile-money.webhook');
    }

    public function provides(): array
    {
        return [MobileMoneyManager::class, 'mobile-money'];
    }
}
