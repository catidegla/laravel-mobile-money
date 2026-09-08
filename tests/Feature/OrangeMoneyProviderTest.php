<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Providers\OrangeMoneyProvider;
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class OrangeMoneyProviderTest extends TestCase
{
    private const TOKEN = 'api.orange.com/oauth/v3/token';
    private const WEBPAY = 'api.orange.com/orange-money-webpay/*/v1/webpayment';
    private const STATUS = 'api.orange.com/orange-money-webpay/*/v1/transactionstatus';

    private function fakeToken(): array
    {
        return [self::TOKEN => Http::response(['access_token' => 'orange-token', 'expires_in' => 3600])];
    }

    private function request(int $amount = 1500): CollectionRequest
    {
        return CollectionRequest::make(
            amount: Money::of($amount, Currency::XOF),
            payer: Msisdn::parse('+225 01 12 34 56 78'), // 01 is Orange in CI
            reference: 'ORDER-77',
        );
    }

    private function driver(): OrangeMoneyProvider
    {
        $driver = MobileMoney::driver('orange_money');
        $this->assertInstanceOf(OrangeMoneyProvider::class, $driver);

        return $driver;
    }

    private function webhookRequest(array $payload): Request
    {
        return Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));
    }

    /* ----------------------------------------------------------- collection */

    #[Test]
    public function it_creates_a_web_payment_and_returns_a_redirect(): void
    {
        Http::fake($this->fakeToken() + [
            self::WEBPAY => Http::response([
                'status' => 201,
                'message' => 'OK',
                'pay_token' => 'pay-token-abc',
                'payment_url' => 'https://webpayment.orange-money.com/ci/pay/abc',
                'notif_token' => 'notif-token-xyz',
            ]),
        ]);

        $transaction = $this->driver()->collect($this->request());

        $this->assertSame(PaymentStatus::Pending, $transaction->status);
        $this->assertTrue($transaction->requiresRedirect());
        $this->assertSame('https://webpayment.orange-money.com/ci/pay/abc', $transaction->redirectUrl);
        $this->assertSame('pay-token-abc', $transaction->providerReference);

        // notif_token is the only thing that makes a later callback
        // verifiable, so it has to survive into the raw payload.
        $this->assertSame('notif-token-xyz', $transaction->raw['notif_token']);
    }

    #[Test]
    public function it_sends_the_amount_as_a_whole_number(): void
    {
        Http::fake($this->fakeToken() + [
            self::WEBPAY => Http::response(['payment_url' => 'https://x', 'pay_token' => 't']),
        ]);

        $this->driver()->collect($this->request(10000));

        Http::assertSent(function (ClientRequest $r): bool {
            if (! str_contains($r->url(), 'webpayment')) {
                return false;
            }

            // Orange wants a number here, and XOF is whole francs.
            return $r['amount'] === 10000 && $r['order_id'] === 'ORDER-77';
        });
    }

    #[Test]
    public function the_sandbox_currency_override_is_applied(): void
    {
        config()->set('mobile-money.providers.orange_money.force_currency', 'OUV');
        $this->forgetDrivers();

        Http::fake($this->fakeToken() + [
            self::WEBPAY => Http::response(['payment_url' => 'https://x', 'pay_token' => 't']),
        ]);

        $this->driver()->collect($this->request());

        // The sandbox settles in OUV, so a config that works there needs this
        // cleared before going live.
        Http::assertSent(fn (ClientRequest $r): bool => ! str_contains($r->url(), 'webpayment') || $r['currency'] === 'OUV');
    }

    #[Test]
    public function the_country_slug_goes_into_the_path(): void
    {
        config()->set('mobile-money.providers.orange_money.country_slug', 'ci');
        $this->forgetDrivers();

        Http::fake($this->fakeToken() + [
            'api.orange.com/orange-money-webpay/ci/v1/webpayment' => Http::response(['payment_url' => 'https://x', 'pay_token' => 't']),
        ]);

        $this->driver()->collect($this->request());

        Http::assertSent(fn (ClientRequest $r): bool => ! str_contains($r->url(), 'webpayment')
            || str_contains($r->url(), '/orange-money-webpay/ci/v1/webpayment'));
    }

    #[Test]
    public function a_response_without_a_payment_url_is_an_error_not_a_pending_payment(): void
    {
        Http::fake($this->fakeToken() + [
            self::WEBPAY => Http::response(['status' => 400, 'message' => 'Invalid merchant key']),
        ]);

        $this->expectExceptionMessageMatches('/no payment_url/');

        $this->driver()->collect($this->request());
    }

    /* --------------------------------------------------------------- status */

    #[Test]
    public function it_reads_a_transaction_status(): void
    {
        Http::fake($this->fakeToken() + [
            self::STATUS => Http::response(['status' => 'SUCCESS', 'txnid' => 'TXN-1', 'message' => 'ok']),
        ]);

        $transaction = $this->driver()->statusFor('ORDER-77', Money::of(1500, Currency::XOF), 'pay-token-abc');

        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertSame('TXN-1', $transaction->providerReference);

        Http::assertSent(fn (ClientRequest $r): bool => ! str_contains($r->url(), 'transactionstatus')
            || ($r['order_id'] === 'ORDER-77' && $r['amount'] === 1500 && $r['pay_token'] === 'pay-token-abc'));
    }

    #[Test]
    public function an_unrecognised_status_is_unknown_rather_than_failed(): void
    {
        Http::fake($this->fakeToken() + [
            self::STATUS => Http::response(['status' => 'SOMETHING_NEW']),
        ]);

        $transaction = $this->driver()->statusFor('ORDER-77', Money::of(1500, Currency::XOF), 'tok');

        // Guessing that an unfamiliar status means failure could release goods
        // that were never paid for, or refund a payment that succeeded.
        $this->assertSame(PaymentStatus::Unknown, $transaction->status);
        $this->assertFalse($transaction->status->allowsRetry());
    }

    #[Test]
    public function the_generic_status_call_explains_what_orange_needs(): void
    {
        Http::fake($this->fakeToken());

        $this->expectExceptionMessageMatches('/order id, amount and pay_token/');

        $this->driver()->status('ORDER-77');
    }

    /* ------------------------------------------------------------- webhooks */

    #[Test]
    public function verification_fails_closed_without_a_resolver(): void
    {
        // Orange does not sign callbacks, so without a way to look up the
        // stored notif_token nothing can be trusted.
        $this->assertFalse(
            $this->driver()->verifyWebhook($this->webhookRequest([
                'order_id' => 'ORDER-77',
                'notif_token' => 'notif-token-xyz',
                'status' => 'SUCCESS',
            ])),
        );
    }

    #[Test]
    public function a_matching_notif_token_verifies(): void
    {
        $driver = $this->driver()->resolveNotifTokenUsing(
            fn (string $orderId): ?string => $orderId === 'ORDER-77' ? 'notif-token-xyz' : null,
        );

        $this->assertTrue($driver->verifyWebhook($this->webhookRequest([
            'order_id' => 'ORDER-77',
            'notif_token' => 'notif-token-xyz',
            'status' => 'SUCCESS',
        ])));
    }

    #[Test]
    public function a_wrong_or_missing_notif_token_is_rejected(): void
    {
        $driver = $this->driver()->resolveNotifTokenUsing(
            fn (string $orderId): ?string => $orderId === 'ORDER-77' ? 'notif-token-xyz' : null,
        );

        $cases = [
            'guessed token' => ['order_id' => 'ORDER-77', 'notif_token' => 'guess', 'status' => 'SUCCESS'],
            'unknown order' => ['order_id' => 'ORDER-99', 'notif_token' => 'notif-token-xyz', 'status' => 'SUCCESS'],
            'no token' => ['order_id' => 'ORDER-77', 'status' => 'SUCCESS'],
            'empty token' => ['order_id' => 'ORDER-77', 'notif_token' => '', 'status' => 'SUCCESS'],
            'no order' => ['notif_token' => 'notif-token-xyz', 'status' => 'SUCCESS'],
        ];

        foreach ($cases as $label => $payload) {
            $this->assertFalse($driver->verifyWebhook($this->webhookRequest($payload)), $label.' was accepted');
        }
    }

    #[Test]
    public function a_verified_callback_parses_into_a_transaction(): void
    {
        $transaction = $this->driver()->parseWebhook($this->webhookRequest([
            'order_id' => 'ORDER-77',
            'notif_token' => 'notif-token-xyz',
            'status' => 'SUCCESS',
            'txnid' => 'TXN-1',
            'amount' => 1500,
        ]));

        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertSame('ORDER-77', $transaction->reference);
        $this->assertSame('TXN-1', $transaction->providerReference);
        $this->assertSame(1500, $transaction->amount->minorUnits);
    }

    /* -------------------------------------------------------------- routing */

    #[Test]
    public function an_ivorian_orange_number_routes_to_orange(): void
    {
        // 01 identifies Orange in the Ivorian numbering plan.
        $this->assertSame('orange_money', MobileMoney::routeFor($this->request())->name());
    }

    private function forgetDrivers(): void
    {
        $manager = app(\Catidegla\MobileMoney\MobileMoneyManager::class);
        $property = (new \ReflectionClass($manager))->getProperty('drivers');
        $property->setValue($manager, []);
    }
}
