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
use Catidegla\MobileMoney\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class MtnMomoProviderTest extends TestCase
{
    private const BASE = 'sandbox.momodeveloper.mtn.com';

    private function fakeToken(): array
    {
        return [self::BASE.'/collection/token/' => Http::response([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])];
    }

    private function request(int $amount = 1500): CollectionRequest
    {
        return CollectionRequest::make(
            amount: Money::of($amount, Currency::XOF),
            payer: Msisdn::parse('+229 01 97 12 34 56'),
            reference: 'ORDER-42',
            description: 'Abonnement mensuel',
        );
    }

    #[Test]
    public function it_initiates_a_collection_and_reports_pending(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        $transaction = MobileMoney::driver('mtn_momo')->collect($this->request());

        $this->assertSame(PaymentStatus::Pending, $transaction->status);
        $this->assertSame('mtn_momo', $transaction->provider);
        $this->assertFalse($transaction->isSettled());
        // MTN pushes a prompt to the handset, so there is nowhere to redirect.
        $this->assertFalse($transaction->requiresRedirect());
    }

    #[Test]
    public function it_sends_the_amount_in_whole_francs(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        MobileMoney::driver('mtn_momo')->collect($this->request(10000));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'requesttopay')) {
                return false;
            }

            // The assertion this package exists for. A cents-assuming library
            // would put "1000000" here.
            return $request['amount'] === '10000' && $request['currency'] === 'XOF';
        });
    }

    #[Test]
    public function it_sends_the_payer_as_an_international_msisdn_without_a_plus(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        MobileMoney::driver('mtn_momo')->collect($this->request());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'requesttopay')) {
                return false;
            }

            return $request['payer']['partyId'] === '2290197123456'
                && $request['payer']['partyIdType'] === 'MSISDN';
        });
    }

    #[Test]
    public function the_idempotency_key_becomes_a_stable_reference_id(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        // A caller passing their own order number must still get a valid UUID,
        // and the same input must always produce the same one, or retrying
        // creates a second charge.
        $request = $this->request()->withIdempotencyKey('ORDER-42-attempt-1');

        $first = MobileMoney::driver('mtn_momo')->collect($request);
        $second = MobileMoney::driver('mtn_momo')->collect($request);

        $this->assertSame($first->reference, $second->reference);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $first->reference,
        );
    }

    #[Test]
    public function it_sends_the_required_mtn_headers(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        MobileMoney::driver('mtn_momo')->collect($this->request());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'requesttopay')) {
                return false;
            }

            return $request->hasHeader('X-Reference-Id')
                && $request->hasHeader('X-Target-Environment', 'sandbox')
                && $request->hasHeader('Ocp-Apim-Subscription-Key', 'test-subscription-key')
                && $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    #[Test]
    public function a_successful_status_settles_the_transaction(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1500',
                'currency' => 'XOF',
                'financialTransactionId' => '1234567890',
                'externalId' => 'ORDER-42',
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => '2290197123456'],
                'status' => 'SUCCESSFUL',
            ]),
        ]);

        $transaction = MobileMoney::driver('mtn_momo')->status('11111111-1111-4111-8111-111111111111');

        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertTrue($transaction->isSettled());
        $this->assertTrue($transaction->isFinal());
        $this->assertSame('1234567890', $transaction->providerReference);
        $this->assertSame(1500, $transaction->amount->minorUnits);
        $this->assertSame('+2290197123456', $transaction->payer?->e164());
    }

    #[Test]
    public function a_customer_declining_is_cancelled_not_failed(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1500',
                'currency' => 'XOF',
                'status' => 'FAILED',
                'reason' => 'PAYER_REJECTION',
            ]),
        ]);

        $transaction = MobileMoney::driver('mtn_momo')->status('11111111-1111-4111-8111-111111111111');

        // MTN reports both a decline and a timeout as FAILED. They mean very
        // different things to a merchant.
        $this->assertSame(PaymentStatus::Cancelled, $transaction->status);
        $this->assertTrue($transaction->status->allowsRetry());
        $this->assertSame('PAYER_REJECTION', $transaction->failureCode);
        $this->assertStringContainsString('declined', (string) $transaction->failureReason);
    }

    #[Test]
    public function an_unanswered_prompt_is_expired(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1500', 'currency' => 'XOF', 'status' => 'FAILED', 'reason' => 'EXPIRED',
            ]),
        ]);

        $this->assertSame(
            PaymentStatus::Expired,
            MobileMoney::driver('mtn_momo')->status('11111111-1111-4111-8111-111111111111')->status,
        );
    }

    #[Test]
    public function insufficient_funds_stays_a_failure_with_a_readable_reason(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1500', 'currency' => 'XOF', 'status' => 'FAILED', 'reason' => 'NOT_ENOUGH_FUNDS',
            ]),
        ]);

        $transaction = MobileMoney::driver('mtn_momo')->status('11111111-1111-4111-8111-111111111111');

        $this->assertSame(PaymentStatus::Failed, $transaction->status);
        $this->assertStringContainsString('balance', (string) $transaction->failureReason);
    }

    #[Test]
    public function a_connection_failure_is_unknown_and_never_retryable(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => fn () => throw new ConnectionException('timed out'),
        ]);

        $transaction = MobileMoney::driver('mtn_momo')->collect($this->request());

        // We do not know whether MTN received the request. Calling this failed
        // would invite a retry, and the retry might be a second charge.
        $this->assertSame(PaymentStatus::Unknown, $transaction->status);
        $this->assertFalse($transaction->status->allowsRetry());
        $this->assertFalse($transaction->status->isFinal());
        $this->assertTrue($transaction->status->isPollable());
    }

    #[Test]
    public function bad_credentials_raise_a_useful_error(): void
    {
        Http::fake([
            self::BASE.'/collection/token/' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/refused the credentials/');

        MobileMoney::driver('mtn_momo')->collect($this->request());
    }

    #[Test]
    public function an_unsupported_market_is_refused_before_any_request(): void
    {
        Http::fake();

        $request = CollectionRequest::make(
            amount: Money::of(1500, Currency::XOF),
            payer: Msisdn::parse('+221 77 123 45 67'), // Senegal, not in this driver's countries
            reference: 'ORDER-43',
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/not configured for XOF in SN/');

        MobileMoney::driver('mtn_momo')->collect($request);
    }

    #[Test]
    public function the_access_token_is_fetched_once_and_cached(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        MobileMoney::driver('mtn_momo')->collect($this->request());
        MobileMoney::driver('mtn_momo')->collect($this->request()->withIdempotencyKey('another-key'));

        $tokenCalls = 0;
        Http::assertSent(function (Request $request) use (&$tokenCalls): bool {
            if (str_ends_with($request->url(), '/collection/token/')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls, 'the token should be reused across calls');
    }
}
