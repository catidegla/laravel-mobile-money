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

        $app['config']->set('mobile-money.providers.wave', [
            'driver' => 'wave',
            'base_url' => 'https://api.wave.com',
            'api_key' => 'wave-test-api-key',
            'webhook_secret' => self::WAVE_SECRET,
            'webhook_tolerance' => 300,
            'success_url' => 'https://merchant.example.com/paid',
            'error_url' => 'https://merchant.example.com/failed',
            'currency' => 'XOF',
            'currencies' => ['XOF'],
            'countries' => ['SN', 'CI'],
            'timeout' => 5,
        ]);

        $app['config']->set('mobile-money.providers.orange_money', [
            'driver' => 'orange_money',
            'base_url' => 'https://api.orange.com',
            'token_path' => '/oauth/v3/token',
            'client_id' => 'orange-client-id',
            'client_secret' => 'orange-client-secret',
            'merchant_key' => 'orange-merchant-key',
            'country_slug' => 'dev',
            'force_currency' => null,
            'return_url' => 'https://merchant.example.com/paid',
            'cancel_url' => 'https://merchant.example.com/cancelled',
            'notif_url' => 'https://merchant.example.com/notify',
            'lang' => 'fr',
            'currency' => 'XOF',
            'currencies' => ['XOF', 'XAF'],
            'countries' => ['CI', 'SN', 'ML', 'BF', 'CM', 'GN'],
            'timeout' => 5,
        ]);
    }

    public const WAVE_SECRET = 'whsec_test_UBS4tGmemXPI0ZL2vHVOEuC3';
}
