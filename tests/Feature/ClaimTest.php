<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Feature;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The row that exists before the provider is called.
 *
 * These are about ordering rather than about payments. The interesting cases
 * are the ones where something goes wrong between writing the row and hearing
 * back, because that gap is the only reason the state exists.
 */
final class ClaimTest extends TestCase
{
    private const BASE = 'sandbox.momodeveloper.mtn.com';

    private function request(string $reference = 'ORDER-42'): CollectionRequest
    {
        return CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+229 01 97 12 34 56'),
            reference: $reference,
        );
    }

    private function fakeAccepted(): void
    {
        Http::fake([
            self::BASE.'/collection/token/' => Http::response([
                'access_token' => 'test-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);
    }

    #[Test]
    public function the_row_exists_before_the_provider_is_called(): void
    {
        $seen = null;

        Http::fake([
            self::BASE.'/collection/token/' => Http::response([
                'access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::BASE.'/collection/v1_0/requesttopay' => function () use (&$seen) {
                // Read the ledger from inside the call. If the row were written
                // afterwards this would find nothing, which is the bug.
                $seen = MobileMoneyTransaction::query()->first()?->status;

                return Http::response('', 202);
            },
        ]);

        MobileMoney::collect($this->request(), 'mtn_momo');

        $this->assertSame(PaymentStatus::Claimed, $seen, 'no claim row existed while the provider was being called');
        $this->assertSame(PaymentStatus::Pending, MobileMoneyTransaction::query()->first()->status);
    }

    #[Test]
    public function a_crash_before_the_reply_leaves_the_claim_behind(): void
    {
        Http::fake([
            self::BASE.'/collection/token/' => Http::response([
                'access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::BASE.'/collection/v1_0/requesttopay' => fn () => throw new ConnectionException('connection reset'),
        ]);

        $transaction = MobileMoney::collect($this->request(), 'mtn_momo');
        $record = MobileMoneyTransaction::query()->first();

        // The driver reports Unknown, and the row records that rather than
        // being deleted or marked failed. Either of those invites a retry.
        $this->assertSame(PaymentStatus::Unknown, $transaction->status);
        $this->assertSame(PaymentStatus::Unknown, $record->status);
        $this->assertFalse($record->status->allowsRetry());
    }

    #[Test]
    public function a_claim_is_queryable_even_though_the_provider_never_answered(): void
    {
        Http::fake([
            self::BASE.'/collection/token/' => Http::response([
                'access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::BASE.'/collection/v1_0/requesttopay' => fn () => throw new ConnectionException('gone'),
        ]);

        $request = $this->request();
        MobileMoney::collect($request, 'mtn_momo');

        // MTN answers status on the X-Reference-Id, which is derived from the
        // idempotency key, so it is known before the call and survives one
        // that never completed.
        $expected = MobileMoney::driver('mtn_momo')->handleFor($request);
        $this->assertSame($expected, MobileMoneyTransaction::query()->first()->provider_reference);
    }

    #[Test]
    public function the_same_key_claims_once_and_does_not_insert_a_second_row(): void
    {
        $this->fakeAccepted();
        $request = $this->request();

        MobileMoney::collect($request, 'mtn_momo');
        MobileMoney::collect($request, 'mtn_momo');

        $this->assertSame(1, MobileMoneyTransaction::query()->count());
    }

    #[Test]
    public function a_settled_payment_is_answered_from_the_ledger_rather_than_the_provider(): void
    {
        $this->fakeAccepted();
        $request = $this->request();

        MobileMoney::collect($request, 'mtn_momo');
        MobileMoneyTransaction::query()->first()->update(['status' => PaymentStatus::Succeeded]);

        Http::fake([
            self::BASE.'/collection/token/' => Http::response([
                'access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::BASE.'/collection/v1_0/requesttopay' => function () {
                $this->fail('a settled payment was sent to the provider again');
            },
        ]);

        $this->assertSame(PaymentStatus::Succeeded, MobileMoney::collect($request, 'mtn_momo')->status);
    }

    #[Test]
    public function a_different_key_for_the_same_order_is_a_separate_claim(): void
    {
        $this->fakeAccepted();

        // A cancelled payment can legitimately be retried for the same order,
        // which is why reference is not the unique column.
        MobileMoney::collect($this->request(), 'mtn_momo');
        MobileMoney::collect($this->request(), 'mtn_momo');

        $this->assertSame(2, MobileMoneyTransaction::query()->where('reference', 'ORDER-42')->count());
    }

    #[Test]
    public function a_claim_is_chased_by_the_reconciler(): void
    {
        $this->fakeAccepted();
        MobileMoney::collect($this->request(), 'mtn_momo');

        MobileMoneyTransaction::query()->first()->update([
            'status' => PaymentStatus::Claimed,
            'next_poll_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, MobileMoneyTransaction::query()->dueForReconciliation()->count());
    }

    #[Test]
    public function a_request_with_no_idempotency_key_is_refused_before_anything_is_sent(): void
    {
        $this->fakeAccepted();

        $bare = new CollectionRequest(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+229 01 97 12 34 56'),
            reference: 'ORDER-42',
        );

        $this->expectException(ProviderException::class);

        try {
            MobileMoney::collect($bare, 'mtn_momo');
        } finally {
            $this->assertSame(0, MobileMoneyTransaction::query()->count());
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function turning_the_ledger_off_writes_nothing(): void
    {
        config()->set('mobile-money.ledger.enabled', false);
        $this->fakeAccepted();

        $this->assertSame(PaymentStatus::Pending, MobileMoney::collect($this->request(), 'mtn_momo')->status);
        $this->assertSame(0, MobileMoneyTransaction::query()->count());
    }
}
