<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | Used when you call the facade without naming a driver and routing cannot
    | decide on its own. Most applications should let routing choose instead,
    | since the right network depends on the customer's number.
    |
    */

    'default' => env('MOBILE_MONEY_PROVIDER', 'mtn_momo'),

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Preference order per ISO 3166-1 alpha-2 country, consulted when the
    | numbering plan does not identify the operator on its own. Cote d'Ivoire
    | encodes the operator in the number itself, so it rarely reaches this.
    |
    */

    'routing' => [
        'BJ' => ['mtn_momo', 'moov'],
        'CI' => ['orange_money', 'mtn_momo', 'moov'],
        'SN' => ['wave', 'orange_money'],
        'TG' => ['moov', 'togocom'],
        'ML' => ['orange_money', 'moov'],
        'BF' => ['orange_money', 'moov'],
        'CM' => ['mtn_momo', 'orange_money'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each entry names a driver and carries its credentials, the currencies it
    | can move and the countries it serves. A payment is only routed to a
    | provider whose currency and country both match, so keep these accurate:
    | they are the guard against sending an XOF payment to a XAF wallet.
    |
    */

    'providers' => [

        'mtn_momo' => [
            'driver' => 'mtn_momo',
            'base_url' => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),

            // "sandbox" or the market code MTN issued you, for example "mtnbenin".
            'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),

            'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
            'api_user' => env('MTN_MOMO_API_USER'),
            'api_key' => env('MTN_MOMO_API_KEY'),

            // The sandbox settles in EUR regardless of market. Production uses
            // the local currency, so this changes when you go live.
            'currency' => env('MTN_MOMO_CURRENCY', 'XOF'),
            'currencies' => ['XOF', 'XAF', 'EUR'],
            'countries' => ['BJ', 'CI', 'CM', 'GN', 'GH', 'UG', 'RW', 'ZM'],

            'callback_url' => env('MTN_MOMO_CALLBACK_URL'),
            'timeout' => 30,
        ],

        'wave' => [
            'driver' => 'wave',
            'base_url' => env('WAVE_BASE_URL', 'https://api.wave.com'),
            'api_key' => env('WAVE_API_KEY'),

            // Generated when you enable request signing on the API key, and
            // shown once. Without it every callback is rejected, which is the
            // safe default.
            'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
            'webhook_tolerance' => 300,

            // Wave is a redirect flow, so both are required before a session
            // can be created.
            'success_url' => env('WAVE_SUCCESS_URL'),
            'error_url' => env('WAVE_ERROR_URL'),

            'currency' => 'XOF',
            'currencies' => ['XOF'],
            'countries' => ['SN', 'CI'],
            'timeout' => 30,
        ],

        'orange_money' => [
            'driver' => 'orange_money',
            'base_url' => env('ORANGE_BASE_URL', 'https://api.orange.com'),
            'token_path' => env('ORANGE_TOKEN_PATH', '/oauth/v3/token'),

            'client_id' => env('ORANGE_CLIENT_ID'),
            'client_secret' => env('ORANGE_CLIENT_SECRET'),
            'merchant_key' => env('ORANGE_MERCHANT_KEY'),

            // The country slug sits in the URL path. "dev" is the sandbox;
            // production uses the market slug Orange issues you. Changing this
            // is one of the two edits needed to go live.
            'country_slug' => env('ORANGE_COUNTRY_SLUG', 'dev'),

            // The other edit. The sandbox settles in OUV rather than the real
            // currency, so a sandbox-passing config would be rejected in
            // production. Leave this null once you are live.
            'force_currency' => env('ORANGE_FORCE_CURRENCY'),

            'return_url' => env('ORANGE_RETURN_URL'),
            'cancel_url' => env('ORANGE_CANCEL_URL'),
            'notif_url' => env('ORANGE_NOTIF_URL'),
            'lang' => 'fr',

            'currency' => 'XOF',
            'currencies' => ['XOF', 'XAF'],
            'countries' => ['CI', 'SN', 'ML', 'BF', 'CM', 'GN'],
            'timeout' => 30,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Callbacks are registered per provider and verified before they are read.
    | Leave verification on. An unverified callback endpoint lets anyone mark
    | any order as paid.
    |
    */

    'webhooks' => [
        'enabled' => env('MOBILE_MONEY_WEBHOOKS', true),
        'path' => 'mobile-money/webhook/{provider}',
        'middleware' => ['api'],

        // Reject callbacks whose timestamp is older than this, in seconds, so
        // a captured request cannot be replayed later.
        'tolerance' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Webhooks in this region are not reliable enough to be the only path to a
    | final state. Poll anything still pending, with a backoff, and stop after
    | the provider's own expiry window.
    |
    */

    'reconciliation' => [
        'enabled' => true,

        // Seconds after initiation to re-check a pending payment.
        'schedule' => [30, 60, 120, 300, 600, 1800, 3600],

        // Give up and mark expired after this many seconds.
        'give_up_after' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | Language for customer-facing strings. Errors aimed at developers stay in
    | English; anything a payer sees is translated.
    |
    */

    'locale' => env('MOBILE_MONEY_LOCALE', 'fr'),

];
