<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Events\PaymentFailed;
use Catidegla\MobileMoney\Events\PaymentSucceeded;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class WebhookEndpointTest extends TestCase
{
    private function record(array $overrides = []): MobileMoneyTransaction
    {
        $request = CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+221 77 123 45 67'),
            reference: 'ORDER-42',
        );

        $record = MobileMoneyTransaction::fromRequest($request, 'wave');
        $record->provider_reference = 'cos-18qq25rgr100a';
        $record->fill($overrides);
        $record->save();

        return $record;
    }

    private function payload(string $type = 'checkout.session.completed', array $data = []): string
    {
        return json_encode([
            'id' => 'EV_1',
            'type' => $type,
            'data' => array_merge([
                'id' => 'cos-18qq25rgr100a',
                'amount' => '1500',
                'currency' => 'XOF',
                'checkout_status' => 'complete',
                'payment_status' => 'succeeded',
                'client_reference' => 'ORDER-42',
                'transaction_id' => 'TCN4Y4ZC3FM',
                'when_completed' => '2026-09-08T10:15:32Z',
            ], $data),
        ]);
    }

    private function sign(string $body, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.$body, $secret ?? self::WAVE_SECRET);
    }

    private function sendWebhook(string $body, ?string $signature, string $provider = 'wave')
    {
        return $this->call(
            'POST',
            "/mobile-money/webhook/{$provider}",
            [],
            [],
            [],
            array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_WAVE_SIGNATURE' => $signature,
            ]),
            $body,
        );
    }

    #[Test]
    public function a_signed_callback_settles_the_transaction_and_fires_an_event(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $record = $this->record();
        $body = $this->payload();

        $this->sendWebhook($body, $this->sign($body))->assertStatus(202);

        $record->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $record->status);
        $this->assertNotNull($record->completed_at);
        // A settled payment is never polled again.
        $this->assertNull($record->next_poll_at);

        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function an_unsigned_callback_changes_nothing(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $record = $this->record();

        // Same 202 as a valid callback: the response must not tell an attacker
        // whether their forgery was detected.
        $this->sendWebhook($this->payload(), null)->assertStatus(202);

        $this->assertSame(PaymentStatus::Pending, $record->refresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_tampered_amount_changes_nothing(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $record = $this->record();
        $body = $this->payload();
        $signature = $this->sign($body);

        $tampered = str_replace('"amount":"1500"', '"amount":"999999"', $body);

        $this->sendWebhook($tampered, $signature)->assertStatus(202);

        $this->assertSame(PaymentStatus::Pending, $record->refresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_replayed_callback_does_not_fire_the_event_twice(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $record = $this->record();
        $body = $this->payload();
        $signature = $this->sign($body);

        // Providers retry. Delivering the order twice is not acceptable.
        $this->sendWebhook($body, $signature)->assertStatus(202);
        $this->sendWebhook($body, $signature)->assertStatus(202);

        $this->assertSame(PaymentStatus::Succeeded, $record->refresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    #[Test]
    public function a_late_failure_callback_cannot_reopen_a_settled_payment(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $record = $this->record();

        $success = $this->payload();
        $this->sendWebhook($success, $this->sign($success))->assertStatus(202);
        $this->assertSame(PaymentStatus::Succeeded, $record->refresh()->status);

        // Out of order delivery is normal. A stale failure arriving after
        // settlement must not undo it.
        $failure = $this->payload('checkout.session.payment_failed', [
            'payment_status' => 'processing',
            'last_payment_error' => ['code' => 'timeout', 'message' => 'too slow'],
        ]);
        $this->sendWebhook($failure, $this->sign($failure))->assertStatus(202);

        $this->assertSame(PaymentStatus::Succeeded, $record->refresh()->status);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_failure_callback_marks_the_payment_failed(): void
    {
        Event::fake([PaymentFailed::class]);

        $record = $this->record();
        $body = $this->payload('checkout.session.payment_failed', [
            'payment_status' => 'processing',
            'checkout_status' => 'open',
            'last_payment_error' => ['code' => 'insufficient-funds', 'message' => 'Solde insuffisant'],
        ]);

        $this->sendWebhook($body, $this->sign($body))->assertStatus(202);

        $record->refresh();
        $this->assertSame(PaymentStatus::Failed, $record->status);
        $this->assertSame('insufficient-funds', $record->failure_code);

        Event::assertDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_callback_for_an_unknown_order_is_accepted_but_ignored(): void
    {
        Event::fake([PaymentSucceeded::class]);

        // No record created. The response is identical to the success case so
        // the endpoint cannot be used to discover which orders exist.
        $body = $this->payload();
        $this->sendWebhook($body, $this->sign($body))->assertStatus(202);

        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_callback_for_an_unknown_provider_is_accepted_but_ignored(): void
    {
        $record = $this->record();
        $body = $this->payload();

        $this->sendWebhook($body, $this->sign($body), 'not_a_provider')->assertStatus(202);

        $this->assertSame(PaymentStatus::Pending, $record->refresh()->status);
    }

    #[Test]
    public function a_provider_that_cannot_verify_is_refused(): void
    {
        $record = $this->record(['provider' => 'mtn_momo']);
        $body = $this->payload();

        // MTN does not sign its callbacks, so the driver does not implement
        // verification and nothing it sends can be trusted yet.
        $this->sendWebhook($body, $this->sign($body), 'mtn_momo')->assertStatus(202);

        $this->assertSame(PaymentStatus::Pending, $record->refresh()->status);
    }

    #[Test]
    public function the_webhook_route_is_not_behind_csrf(): void
    {
        // A callback carries no session, so a CSRF-protected route would
        // reject every provider notification with a 419.
        $body = $this->payload();

        $this->sendWebhook($body, $this->sign($body))->assertStatus(202);
    }
}
