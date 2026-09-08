<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Carbon\CarbonImmutable;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Events\PaymentSucceeded;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class ReconciliationTest extends TestCase
{
    private const BASE = 'sandbox.momodeveloper.mtn.com';

    private function pending(array $overrides = []): MobileMoneyTransaction
    {
        $request = CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+229 01 97 12 34 56'),
            reference: 'ORDER-42',
        );

        $record = MobileMoneyTransaction::fromRequest($request, 'mtn_momo');
        $record->provider_reference = '11111111-1111-4111-8111-111111111111';
        $record->next_poll_at = CarbonImmutable::now()->subMinute();
        $record->fill($overrides);
        $record->save();

        return $record;
    }

    private function fakeStatus(string $status, ?string $reason = null): void
    {
        Http::fake([
            self::BASE.'/collection/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            self::BASE.'/collection/v1_0/requesttopay/*' => Http::response(array_filter([
                'amount' => '1500',
                'currency' => 'XOF',
                'status' => $status,
                'reason' => $reason,
                'financialTransactionId' => '999',
            ])),
        ]);
    }

    #[Test]
    public function it_settles_a_payment_the_webhook_never_reported(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $record = $this->pending();
        $this->fakeStatus('SUCCESSFUL');

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $record->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $record->status);
        $this->assertNotNull($record->completed_at);
        $this->assertNull($record->next_poll_at, 'a settled payment should stop being polled');

        Event::assertDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_still_pending_payment_is_rescheduled_with_a_backoff(): void
    {
        $record = $this->pending();
        $this->fakeStatus('PENDING');

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $record->refresh();
        $this->assertSame(PaymentStatus::Pending, $record->status);
        $this->assertSame(1, $record->poll_attempts);
        $this->assertNotNull($record->next_poll_at);
        $this->assertTrue($record->next_poll_at->isFuture());
    }

    #[Test]
    public function the_backoff_lengthens_with_each_attempt(): void
    {
        $record = $this->pending();
        $this->fakeStatus('PENDING');

        $delays = [];
        foreach (range(1, 3) as $round) {
            $record->next_poll_at = CarbonImmutable::now()->subMinute();
            $record->save();

            $this->artisan('mobile-money:reconcile')->assertSuccessful();

            $record->refresh();
            $delays[] = CarbonImmutable::now()->diffInSeconds($record->next_poll_at, true);
        }

        // Configured schedule is 30, 60, 120, ... so each wait is longer than
        // the last. Polling a dead payment every 30 seconds forever is how you
        // get rate limited by the provider.
        $this->assertGreaterThan($delays[0], $delays[1]);
        $this->assertGreaterThan($delays[1], $delays[2]);
    }

    #[Test]
    public function a_payment_stops_being_polled_once_the_schedule_runs_out(): void
    {
        $schedule = (array) config('mobile-money.reconciliation.schedule');
        $record = $this->pending(['poll_attempts' => count($schedule)]);
        $this->fakeStatus('PENDING');

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertNull($record->refresh()->next_poll_at, 'polling should give up eventually');
    }

    #[Test]
    public function an_unreachable_provider_does_not_stop_the_run(): void
    {
        $broken = $this->pending(['reference' => 'ORDER-BROKEN']);
        $broken->provider = 'not_a_provider';
        $broken->save();

        $healthy = $this->pending(['reference' => 'ORDER-OK', 'idempotency_key' => 'key-2']);
        $this->fakeStatus('SUCCESSFUL');

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        // One broken row must not strand every other payment in the queue.
        $this->assertSame(PaymentStatus::Succeeded, $healthy->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $broken->refresh()->status);
        $this->assertSame(1, $broken->poll_attempts);
    }

    #[Test]
    public function payments_that_are_not_due_are_left_alone(): void
    {
        $record = $this->pending(['next_poll_at' => CarbonImmutable::now()->addHour()]);
        Http::fake();

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertSame(0, $record->refresh()->poll_attempts);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_settled_payment_is_never_reconciled_again(): void
    {
        $record = $this->pending([
            'status' => PaymentStatus::Succeeded,
            'next_poll_at' => CarbonImmutable::now()->subMinute(),
        ]);
        Http::fake();

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Succeeded, $record->refresh()->status);
    }

    #[Test]
    public function an_unknown_status_is_still_reconciled(): void
    {
        // Unknown is the state left behind by a timeout during collection. It
        // is exactly what reconciliation exists to resolve, so it must be
        // picked up rather than treated as final.
        $record = $this->pending(['status' => PaymentStatus::Unknown]);
        $this->fakeStatus('SUCCESSFUL');

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, $record->refresh()->status);
    }

    #[Test]
    public function reconciliation_can_be_limited_to_one_provider(): void
    {
        $wave = $this->pending(['provider' => 'wave', 'reference' => 'ORDER-W', 'idempotency_key' => 'key-w']);
        $mtn = $this->pending(['reference' => 'ORDER-M', 'idempotency_key' => 'key-m']);
        $this->fakeStatus('SUCCESSFUL');

        $this->artisan('mobile-money:reconcile', ['--provider' => 'mtn_momo'])->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, $mtn->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $wave->refresh()->status);
        $this->assertSame(0, $wave->poll_attempts);
    }

    #[Test]
    public function it_does_nothing_when_disabled(): void
    {
        config()->set('mobile-money.reconciliation.enabled', false);
        $this->pending();
        Http::fake();

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }
}
