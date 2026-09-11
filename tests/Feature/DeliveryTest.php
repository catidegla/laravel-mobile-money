<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\Delivery;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Events\PaymentFailed;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Catidegla\MobileMoney\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * What our own side saw of the outbound request, and what it lets us conclude.
 *
 * The question underneath all of this: a provider answering "no transaction
 * with that reference" means either that it never got the request or that it
 * has not indexed one it did get, and it does not say which. Nothing on the
 * provider's side separates them. Our HTTP client does.
 */
final class DeliveryTest extends TestCase
{
    private const MTN_NUMBER = '+229 01 97 12 34 56';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mobile-money.default', 'mtn_momo');
        $app['config']->set('mobile-money.ledger.enabled', true);
        $app['config']->set('mobile-money.reconciliation.enabled', true);
    }

    /* ------------------------------------------------------- classification */

    #[Test]
    public function a_connection_that_never_opened_proves_the_request_never_arrived(): void
    {
        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->neverOpened()));
    }

    #[Test]
    public function a_failure_after_the_connection_opened_proves_nothing(): void
    {
        // The body may well have gone out and only the answer got lost. This
        // is the case the whole design has to keep ambiguous.
        $this->assertSame(Delivery::Indeterminate, Delivery::classify($this->openedThenFailed()));
    }

    #[Test]
    public function a_failure_carrying_nothing_useful_falls_through_to_indeterminate(): void
    {
        // The asymmetry is deliberate. Calling an arrived request never sent
        // fails a live payment; calling a lost one indeterminate only keeps it
        // being polled, which is what already happens.
        $this->assertSame(Delivery::Indeterminate, Delivery::classify(new ConnectionException('cURL error 99')));
    }

    #[Test]
    public function guzzle_7_is_read_from_the_curl_context_rather_than_the_class(): void
    {
        if (! self::readsCurlContext()) {
            $this->markTestSkipped('Guzzle 8 sorts these into typed exceptions and no longer exposes the context.');
        }

        //  6 host not resolved, 7 connection refused, 35 handshake failed.
        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->curlFailure(errno: 6)));
        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->curlFailure(errno: 7)));
        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->curlFailure(errno: 35)));

        // 52 got nothing back: the request went out, so nothing is proven.
        $this->assertSame(Delivery::Indeterminate, Delivery::classify($this->curlFailure(errno: 52)));

        // 28 is a timeout and covers both cases. Only a connection that was
        // never established settles it, which curl records as connect_time 0.
        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->curlFailure(errno: 28, connectTime: 0.0)));
        $this->assertSame(Delivery::Indeterminate, Delivery::classify($this->curlFailure(errno: 28, connectTime: 0.031)));
    }

    #[Test]
    public function guzzle_8_is_read_from_the_class_rather_than_the_context(): void
    {
        if (self::readsCurlContext()) {
            $this->markTestSkipped('Guzzle 7 keeps one exception class for every connection failure.');
        }

        // ConnectTimeoutException extends ConnectException, so the one check
        // covers a refused connection and a timeout before one was opened.
        $timeout = 'GuzzleHttp\Exception\ConnectTimeoutException';

        $this->assertSame(Delivery::NeverSent, Delivery::classify($this->wrap(
            new $timeout('Connection timed out', new PsrRequest('POST', 'https://example.test')),
        )));
    }

    /* ------------------------------------------------------------- merging */

    #[Test]
    public function one_delivered_attempt_cannot_be_unsaid_by_a_later_failure(): void
    {
        $this->assertSame(Delivery::Delivered, Delivery::Delivered->strongest(Delivery::NeverSent));
        $this->assertSame(Delivery::Delivered, Delivery::NeverSent->strongest(Delivery::Delivered));
        $this->assertSame(Delivery::Indeterminate, Delivery::NeverSent->strongest(Delivery::Indeterminate));
        $this->assertSame(Delivery::NeverSent, Delivery::Unattempted->strongest(Delivery::NeverSent));
    }

    #[Test]
    public function only_never_sent_proves_anything(): void
    {
        $this->assertTrue(Delivery::NeverSent->provesNeverArrived());

        foreach ([Delivery::Unattempted, Delivery::Indeterminate, Delivery::Delivered] as $other) {
            $this->assertFalse($other->provesNeverArrived(), $other->value);
        }
    }

    /* ------------------------------------------------------------ recording */

    #[Test]
    public function a_claim_starts_out_having_attempted_nothing(): void
    {
        $record = MobileMoneyTransaction::claim($this->request(), 'mtn_momo');

        $this->assertSame(Delivery::Unattempted, $record->delivery);
    }

    #[Test]
    public function a_provider_that_answers_marks_the_row_delivered(): void
    {
        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay' => Http::response('', 202),
        ]);

        MobileMoney::collect($request = $this->request());

        $this->assertSame(Delivery::Delivered, $this->row($request)->delivery);
    }

    #[Test]
    public function a_connection_that_never_opened_is_written_to_the_row(): void
    {
        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay' => fn () => throw $this->neverOpened(),
        ]);

        $transaction = MobileMoney::collect($request = $this->request());
        $record = $this->row($request);

        // The payment itself stays Unknown, because a retry under a new key
        // could still be a second charge until somebody checks.
        $this->assertSame(PaymentStatus::Unknown, $transaction->status);
        $this->assertSame(Delivery::NeverSent, $record->delivery);
        $this->assertSame(PaymentStatus::Unknown, $record->status);
    }

    #[Test]
    public function a_rejection_still_counts_as_the_provider_having_the_request(): void
    {
        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay' => Http::response(['message' => 'nope'], 500),
        ]);

        try {
            MobileMoney::collect($request = $this->request());
            $this->fail('the rejection should have surfaced');
        } catch (ProviderException $e) {
            $this->assertTrue($e->receivedAnswer());
        }

        $this->assertSame(Delivery::Delivered, $this->row($request)->delivery);
    }

    /* ------------------------------------------------------- the conclusion */

    #[Test]
    public function a_never_sent_payment_the_provider_does_not_recognise_is_closed(): void
    {
        Event::fake([PaymentFailed::class]);

        $record = $this->neverSentRow();

        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $record->refresh();

        $this->assertSame(PaymentStatus::Failed, $record->status);
        $this->assertSame('never_sent', $record->failure_code);
        $this->assertNull($record->next_poll_at);
        $this->assertNotNull($record->completed_at);
        Event::assertDispatched(PaymentFailed::class);
    }

    #[Test]
    public function the_same_404_without_that_evidence_keeps_polling(): void
    {
        Event::fake([PaymentFailed::class]);

        // Identical in every way except what our own side saw. A timeout after
        // the body went out leaves the provider possibly holding the request,
        // so the 404 may simply mean not indexed yet.
        $record = $this->neverSentRow(Delivery::Indeterminate);

        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $record->refresh();

        $this->assertSame(PaymentStatus::Claimed, $record->status);
        $this->assertNotNull($record->next_poll_at);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_row_written_before_the_column_existed_concludes_nothing(): void
    {
        Event::fake([PaymentFailed::class]);

        $record = $this->neverSentRow();
        $record->forceFill(['delivery' => null])->save();

        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Claimed, $record->refresh()->status);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_never_sent_row_the_provider_does_recognise_is_left_alone(): void
    {
        // The other half of the evidence is missing: MTN answering at all means
        // it has the request, whatever our client thought it saw.
        $record = $this->neverSentRow();

        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay/*' => Http::response(['status' => 'PENDING', 'amount' => '1500', 'currency' => 'XOF']),
        ]);

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $record->refresh()->status);
    }

    #[Test]
    public function closing_it_releases_the_order_for_another_attempt(): void
    {
        // The reason Failed is the right state rather than Unknown. Unknown
        // exists to block a retry that might be a second charge; there is
        // nothing here to charge twice.
        $record = $this->neverSentRow();

        Http::fake([
            '*/token/' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/requesttopay/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->artisan('mobile-money:reconcile')->assertSuccessful();

        $this->assertTrue($record->refresh()->status->allowsRetry());
    }

    /* --------------------------------------------------------------- helpers */

    private function request(string $reference = 'ORDER-1'): CollectionRequest
    {
        return CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse(self::MTN_NUMBER),
            reference: $reference,
        );
    }

    private function row(CollectionRequest $request): MobileMoneyTransaction
    {
        return MobileMoneyTransaction::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->firstOrFail();
    }

    /** A claimed row that is due for reconciliation, carrying the given evidence. */
    private function neverSentRow(Delivery $delivery = Delivery::NeverSent): MobileMoneyTransaction
    {
        $record = MobileMoneyTransaction::claim($this->request(), 'mtn_momo');
        $record->forceFill([
            'delivery' => $delivery,
            'next_poll_at' => now()->subMinute(),
        ])->save();

        return $record;
    }

    /** Whether the installed Guzzle still carries curl's numbers on the exception. */
    private static function readsCurlContext(): bool
    {
        return method_exists(ConnectException::class, 'getHandlerContext');
    }

    /**
     * A failure where the connection never opened.
     *
     * Built the way the installed Guzzle would build it. On 8 the class is the
     * whole answer and the constructor takes three arguments; on 7 the class
     * proves nothing and the curl context has to carry it.
     */
    private function neverOpened(): ConnectionException
    {
        return self::readsCurlContext()
            ? $this->curlFailure(errno: 7)
            : $this->wrap(new ConnectException('Connection refused', new PsrRequest('POST', 'https://example.test')));
    }

    /**
     * A failure after the connection opened, where the body may have gone out.
     *
     * Guzzle 8 raises a NetworkException for this, which is deliberately not a
     * subclass of ConnectException. Guzzle 7 raises a ConnectException with a
     * curl timeout on it and a connection that was established.
     */
    private function openedThenFailed(): ConnectionException
    {
        if (self::readsCurlContext()) {
            return $this->curlFailure(errno: 28, connectTime: 0.031);
        }

        $network = 'GuzzleHttp\Exception\NetworkException';

        return $this->wrap(new $network('Operation timed out', new PsrRequest('POST', 'https://example.test')));
    }

    /** Guzzle 7's shape: one exception class, curl's own numbers on the side. */
    private function curlFailure(int $errno, ?float $connectTime = null): ConnectionException
    {
        $context = ['errno' => $errno, 'error' => 'curl error '.$errno];

        if ($connectTime !== null) {
            $context['connect_time'] = $connectTime;
        }

        return $this->wrap(
            new ConnectException('cURL error '.$errno, new PsrRequest('POST', 'https://example.test'), null, $context),
        );
    }

    private function wrap(\Throwable $previous): ConnectionException
    {
        return new ConnectionException($previous->getMessage(), 0, $previous);
    }
}
