<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Catidegla\MobileMoney\Contracts\VerifiesWebhooks;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class WaveProviderTest extends TestCase
{
    private const SESSIONS = 'api.wave.com/v1/checkout/sessions';

    private function sessionPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cos-18qq25rgr100a',
            'amount' => '1500',
            'currency' => 'XOF',
            'checkout_status' => 'open',
            'payment_status' => 'processing',
            'client_reference' => 'ORDER-42',
            'wave_launch_url' => 'https://pay.wave.com/c/cos-18qq25rgr100a',
            'when_created' => '2026-09-08T10:00:00Z',
        ], $overrides);
    }

    private function request(int $amount = 1500): CollectionRequest
    {
        return CollectionRequest::make(
            amount: Money::of($amount, Currency::XOF),
            payer: Msisdn::parse('+221 77 123 45 67'),
            reference: 'ORDER-42',
        );
    }

    /** Sign a body exactly the way Wave does: timestamp concatenated to the raw body. */
    private function sign(string $rawBody, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.$rawBody, $secret ?? self::WAVE_SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    private function webhookRequest(string $rawBody, ?string $signature): Request
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $rawBody);

        if ($signature !== null) {
            $request->headers->set('Wave-Signature', $signature);
        }

        return $request;
    }

    /* ----------------------------------------------------------- collection */

    #[Test]
    public function it_creates_a_checkout_session_and_returns_a_redirect(): void
    {
        Http::fake([self::SESSIONS => Http::response($this->sessionPayload())]);

        $transaction = MobileMoney::driver('wave')->collect($this->request());

        $this->assertSame(PaymentStatus::Pending, $transaction->status);
        $this->assertSame('cos-18qq25rgr100a', $transaction->reference);

        // Wave is a redirect flow, unlike MTN's handset prompt. Calling code
        // has to branch on this.
        $this->assertTrue($transaction->requiresRedirect());
        $this->assertSame('https://pay.wave.com/c/cos-18qq25rgr100a', $transaction->redirectUrl);
    }

    #[Test]
    public function it_sends_whole_francs_as_a_string(): void
    {
        Http::fake([self::SESSIONS => Http::response($this->sessionPayload(['amount' => '10000']))]);

        MobileMoney::driver('wave')->collect($this->request(10000));

        Http::assertSent(fn (ClientRequest $r): bool => $r['amount'] === '10000' && $r['currency'] === 'XOF');
    }

    #[Test]
    public function it_restricts_the_session_to_the_payer(): void
    {
        Http::fake([self::SESSIONS => Http::response($this->sessionPayload())]);

        MobileMoney::driver('wave')->collect($this->request());

        // Without this a leaked checkout URL can be paid by anyone and still
        // credited to this order.
        Http::assertSent(fn (ClientRequest $r): bool => $r['restrict_payer_mobile'] === '+221771234567');
    }

    #[Test]
    public function the_client_reference_is_truncated_to_the_documented_limit(): void
    {
        Http::fake([self::SESSIONS => Http::response($this->sessionPayload())]);

        $request = CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+221 77 123 45 67'),
            reference: str_repeat('A', 400),
        );

        MobileMoney::driver('wave')->collect($request);

        Http::assertSent(fn (ClientRequest $r): bool => mb_strlen($r['client_reference']) === 255);
    }

    /* --------------------------------------------------------------- status */

    #[Test]
    public function a_succeeded_payment_settles(): void
    {
        Http::fake([self::SESSIONS.'/*' => Http::response($this->sessionPayload([
            'checkout_status' => 'complete',
            'payment_status' => 'succeeded',
            'transaction_id' => 'TCN4Y4ZC3FM',
            'when_completed' => '2026-09-08T10:15:32Z',
        ]))]);

        $transaction = MobileMoney::driver('wave')->status('cos-18qq25rgr100a');

        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertTrue($transaction->isSettled());
        $this->assertSame('TCN4Y4ZC3FM', $transaction->providerReference);
        $this->assertNotNull($transaction->completedAt);
    }

    #[Test]
    public function an_expired_session_is_not_reported_as_a_failure(): void
    {
        Http::fake([self::SESSIONS.'/*' => Http::response($this->sessionPayload([
            'checkout_status' => 'expired',
            'payment_status' => 'processing',
        ]))]);

        $transaction = MobileMoney::driver('wave')->status('cos-18qq25rgr100a');

        // Wave reports two orthogonal statuses. Reading only payment_status
        // here would leave this stuck as pending forever.
        $this->assertSame(PaymentStatus::Expired, $transaction->status);
        $this->assertTrue($transaction->status->allowsRetry());
    }

    #[Test]
    public function a_cancelled_payment_is_distinguished_from_a_failure(): void
    {
        Http::fake([self::SESSIONS.'/*' => Http::response($this->sessionPayload([
            'checkout_status' => 'complete',
            'payment_status' => 'cancelled',
        ]))]);

        $this->assertSame(
            PaymentStatus::Cancelled,
            MobileMoney::driver('wave')->status('cos-18qq25rgr100a')->status,
        );
    }

    /* -------------------------------------------------------------- webhooks */

    private function verifier(): VerifiesWebhooks
    {
        $driver = MobileMoney::driver('wave');
        $this->assertInstanceOf(VerifiesWebhooks::class, $driver);

        return $driver;
    }

    #[Test]
    public function a_correctly_signed_callback_verifies(): void
    {
        $body = json_encode(['id' => 'EV_1', 'type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);

        $this->assertTrue(
            $this->verifier()->verifyWebhook($this->webhookRequest($body, $this->sign($body))),
        );
    }

    #[Test]
    public function a_tampered_body_is_rejected(): void
    {
        $body = json_encode(['id' => 'EV_1', 'type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);
        $signature = $this->sign($body);

        // An attacker raising the amount after the signature was computed.
        $tampered = str_replace('"amount":"1500"', '"amount":"1"', $body);

        $this->assertNotSame($body, $tampered, 'the tamper should have changed the body');
        $this->assertFalse(
            $this->verifier()->verifyWebhook($this->webhookRequest($tampered, $signature)),
        );
    }

    #[Test]
    public function a_signature_from_the_wrong_secret_is_rejected(): void
    {
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);

        $this->assertFalse(
            $this->verifier()->verifyWebhook(
                $this->webhookRequest($body, $this->sign($body, null, 'whsec_attacker_guess')),
            ),
        );
    }

    #[Test]
    public function a_replayed_callback_is_rejected_once_it_is_stale(): void
    {
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);

        // Correctly signed, but captured an hour ago. Tolerance is 300s.
        $stale = $this->sign($body, time() - 3600);

        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($body, $stale)));

        // The same body inside the window is fine, so the rejection is about
        // age rather than the signature being wrong.
        $this->assertTrue($this->verifier()->verifyWebhook($this->webhookRequest($body, $this->sign($body, time() - 60))));
    }

    #[Test]
    public function a_callback_with_no_signature_is_rejected(): void
    {
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);

        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($body, null)));
        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($body, '')));
        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($body, 'garbage')));
        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($body, 't=abc,v1=')));
    }

    #[Test]
    public function both_signatures_are_accepted_during_key_rotation(): void
    {
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);
        $timestamp = time();

        $old = hash_hmac('sha256', $timestamp.$body, 'whsec_previous_key');
        $new = hash_hmac('sha256', $timestamp.$body, self::WAVE_SECRET);

        // Wave sends more than one v1 while a key is being rotated.
        $header = "t={$timestamp},v1={$old},v1={$new}";

        $this->assertTrue($this->verifier()->verifyWebhook($this->webhookRequest($body, $header)));
    }

    #[Test]
    public function verification_uses_the_raw_body_not_re_encoded_json(): void
    {
        // Same data, different key order. Signing one and verifying the other
        // must fail, which proves the raw bytes are used rather than a decode
        // and re-encode round trip.
        $ordered = '{"amount":"1500","currency":"XOF","id":"cos-1"}';
        $reordered = '{"id":"cos-1","currency":"XOF","amount":"1500"}';

        $signature = $this->sign($ordered);

        $this->assertTrue($this->verifier()->verifyWebhook($this->webhookRequest($ordered, $signature)));
        $this->assertFalse($this->verifier()->verifyWebhook($this->webhookRequest($reordered, $signature)));
    }

    #[Test]
    public function verification_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('mobile-money.providers.wave.webhook_secret', null);

        // Rebuild the driver so it picks up the changed config.
        $driver = app(\Catidegla\MobileMoney\MobileMoneyManager::class);
        $fresh = (new \ReflectionClass($driver))->getProperty('drivers');
        $fresh->setValue($driver, []);

        $body = json_encode(['type' => 'checkout.session.completed', 'data' => $this->sessionPayload()]);

        // Without a secret nothing can be verified, so everything is refused.
        // The alternative, accepting unsigned callbacks, is a public endpoint
        // for marking any order paid.
        $this->assertFalse($driver->driver('wave')->verifyWebhook($this->webhookRequest($body, $this->sign($body))));
    }

    #[Test]
    public function a_verified_callback_parses_into_a_transaction(): void
    {
        $body = json_encode([
            'id' => 'EV_QvEZuDSQbLdI',
            'type' => 'checkout.session.completed',
            'data' => $this->sessionPayload([
                'checkout_status' => 'complete',
                'payment_status' => 'succeeded',
                'transaction_id' => 'TCN4Y4ZC3FM',
                'when_completed' => '2026-09-08T10:15:32Z',
            ]),
        ]);

        $transaction = $this->verifier()->parseWebhook($this->webhookRequest($body, $this->sign($body)));

        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertSame(1500, $transaction->amount->minorUnits);
        $this->assertSame(Currency::XOF, $transaction->amount->currency);
        $this->assertSame('TCN4Y4ZC3FM', $transaction->providerReference);
    }

    #[Test]
    public function a_payment_failed_callback_carries_the_reason(): void
    {
        $body = json_encode([
            'type' => 'checkout.session.payment_failed',
            'data' => $this->sessionPayload([
                'payment_status' => 'processing',
                'last_payment_error' => ['code' => 'insufficient-funds', 'message' => 'Solde insuffisant'],
            ]),
        ]);

        $transaction = $this->verifier()->parseWebhook($this->webhookRequest($body, $this->sign($body)));

        $this->assertSame(PaymentStatus::Failed, $transaction->status);
        $this->assertSame('insufficient-funds', $transaction->failureCode);
        $this->assertSame('Solde insuffisant', $transaction->failureReason);
    }

    /* -------------------------------------------------------------- routing */

    #[Test]
    public function senegal_routes_to_wave_by_configuration(): void
    {
        Http::fake([self::SESSIONS => Http::response($this->sessionPayload())]);

        // MTN does not serve SN, and the routing table prefers wave there.
        $driver = MobileMoney::routeFor($this->request());

        $this->assertSame('wave', $driver->name());
    }

    #[Test]
    public function an_ivorian_mtn_number_routes_away_from_wave(): void
    {
        $request = CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+225 05 12 34 56 78'), // 05 is MTN
            reference: 'ORDER-99',
        );

        // Both drivers serve CI, but the number itself says MTN.
        $this->assertSame('mtn_momo', MobileMoney::routeFor($request)->name());
    }
}
