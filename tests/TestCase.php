<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests;

use Catidegla\MobileMoney\MobileMoneyServiceProvider;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Access tokens are cached, and a token left over from another test
        // would hide a broken authentication path.
        Cache::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [MobileMoneyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        $app['config']->set('mobile-money.providers.mtn_momo', [
            'driver' => 'mtn_momo',
            'base_url' => 'https://sandbox.momodeveloper.mtn.com',
            'environment' => 'sandbox',
            'subscription_key' => 'test-subscription-key',
            'api_user' => 'test-api-user',
            'api_key' => 'test-api-key',
            'currency' => 'XOF',
            'currencies' => ['XOF', 'XAF', 'EUR'],
            'countries' => ['BJ', 'CI', 'CM', 'GN'],
            'callback_url' => 'https://merchant.example.com/webhook',
            'timeout' => 5,
        ]);
    }
}
