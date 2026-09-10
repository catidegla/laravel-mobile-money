<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Providers;

use Catidegla\MobileMoney\Contracts\Provider;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use DateTimeImmutable;
use Catidegla\MobileMoney\Enums\Delivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MTN Mobile Money, Collections API.
 *
 * Push flow: the customer receives a prompt on their handset and types their
 * PIN. Nothing is redirected, so there is no URL to send them to.
 *
 * The one detail that shapes this driver is that requesttopay answers 202
 * Accepted with an empty body. It tells you the request was queued, not that
 * anything happened. The X-Reference-Id you generated is the only handle you
 * get, which is also why it doubles as the idempotency key.
 *
 * Contract built from MTN's published Collections documentation. See the
 * README for which paths have been exercised against a live sandbox.
 */
final class MtnMomoProvider implements Provider
{
    /** MTN's own status vocabulary. */
    private const STATUS_MAP = [
        'PENDING' => PaymentStatus::Pending,
        'SUCCESSFUL' => PaymentStatus::Succeeded,
        'FAILED' => PaymentStatus::Failed,
    ];

    /**
     * Failure reasons that are not really failures.
     *
     * A customer declining the prompt and a customer never answering it are
     * both reported as FAILED, but they mean different things to a merchant
     * and only some of them are worth prompting again for.
     */
    private const REASON_MAP = [
        'PAYER_REJECTION' => PaymentStatus::Cancelled,
        'APPROVAL_REJECTED' => PaymentStatus::Cancelled,
        'EXPIRED' => PaymentStatus::Expired,
        'PAYER_DELAYED' => PaymentStatus::Expired,
    ];

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'mtn_momo';
    }

    public function collect(CollectionRequest $request): Transaction
    {
        $this->assertSupported($request->amount->currency, $request->payer->country);

        // MTN requires a UUID here and rejects anything else, so a caller
        // supplying their own order number as the key would fail opaquely.
        $reference = $this->asUuid($request->idempotencyKey);

        $headers = [
            'X-Reference-Id' => $reference,
            'X-Target-Environment' => $this->config['environment'] ?? 'sandbox',
            'Content-Type' => 'application/json',
        ];

        if ($callback = $request->callbackUrl ?? $this->config['callback_url'] ?? null) {
            $headers['X-Callback-Url'] = $callback;
        }

        $payload = [
            'amount' => $request->amount->forProvider(),
            'currency' => $request->amount->currency->value,
            'externalId' => $request->reference,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $request->payer->msisdn(),
            ],
            'payerMessage' => $this->truncate($request->description ?? $request->reference, 160),
            'payeeNote' => $this->truncate($request->reference, 160),
        ];

        try {
            $response = $this->client()->withHeaders($headers)
                ->post('/collection/v1_0/requesttopay', $payload);
        } catch (ConnectionException $e) {
            // We do not know whether MTN received this. Reporting it as failed
            // would invite a retry, and a retry might be a second charge.
            //
            // What we can record is what our own side saw, which is the only
            // evidence that will ever separate a request MTN never got from
            // one it has not indexed yet.
            return $this->unknown($request, $reference, $e->getMessage(), Delivery::classify($e));
        }

        // A replayed X-Reference-Id comes back as 409 RESOURCE_ALREADY_EXIST.
        // That is the idempotency key working, not a rejection: the first
        // request was accepted and this one changed nothing. Throwing here
        // would push a caller retrying after a timeout into issuing a fresh
        // reference, which is the second charge the key exists to prevent.
        // The state is whatever the original request reached, so report it as
        // pending and let the caller poll for the truth.
        if ($response->status() === 409) {
            return new Transaction(
                status: PaymentStatus::Pending,
                amount: $request->amount,
                reference: $reference,
                provider: $this->name(),
                payer: $request->payer,
                raw: ['http_status' => 409, 'duplicate' => true],
                delivery: Delivery::Delivered,
            );
        }

        if ($response->status() !== 202) {
            throw ProviderException::rejected($this->name(), $response->status(), $this->errorBody($response));
        }

        return new Transaction(
            status: PaymentStatus::Pending,
            amount: $request->amount,
            reference: $reference,
            provider: $this->name(),
            payer: $request->payer,
            raw: ['http_status' => 202],
            delivery: Delivery::Delivered,
        );
    }

    public function status(string $reference): Transaction
    {
        try {
            $response = $this->client()
                ->withHeaders(['X-Target-Environment' => $this->config['environment'] ?? 'sandbox'])
                ->get("/collection/v1_0/requesttopay/{$reference}");
        } catch (ConnectionException $e) {
            return new Transaction(
                status: PaymentStatus::Unknown,
                amount: Money::ofMinor(0, $this->defaultCurrency()),
                reference: $reference,
                provider: $this->name(),
                failureReason: $e->getMessage(),
            );
        }

        if ($response->status() === 404) {
            throw ProviderException::notFound($this->name(), $reference);
        }

        if (! $response->successful()) {
            throw ProviderException::rejected($this->name(), $response->status(), $this->errorBody($response));
        }

        return $this->toTransaction($response->json(), $reference);
    }

    /** @param array<string, mixed> $body */
    private function toTransaction(array $body, string $reference): Transaction
    {
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;
        $status = self::STATUS_MAP[$body['status'] ?? ''] ?? PaymentStatus::Unknown;

        // Refine FAILED using the reason, so a decline is not confused with a
        // technical failure.
        if ($status === PaymentStatus::Failed && $reason !== null) {
            $status = self::REASON_MAP[strtoupper($reason)] ?? PaymentStatus::Failed;
        }

        $currency = isset($body['currency'])
            ? Currency::tryFrom((string) $body['currency']) ?? $this->defaultCurrency()
            : $this->defaultCurrency();

        $amount = isset($body['amount'])
            ? Money::of($body['amount'], $currency)
            : Money::ofMinor(0, $currency);

        $payer = null;
        if (isset($body['payer']['partyId'])) {
            try {
                $payer = Msisdn::parse('+'.$body['payer']['partyId']);
            } catch (\Throwable) {
                // A number we cannot parse is not a reason to lose the result.
                $payer = null;
            }
        }

        return new Transaction(
            status: $status,
            amount: $amount,
            reference: $reference,
            provider: $this->name(),
            providerReference: $body['financialTransactionId'] ?? null,
            payer: $payer,
            failureCode: $reason,
            failureReason: $reason !== null ? $this->describeReason($reason) : null,
            completedAt: $status->isFinal() ? new DateTimeImmutable() : null,
            raw: $body,
        );
    }

    /** MTN's codes are terse. Give support something readable. */
    private function describeReason(string $code): string
    {
        return match (strtoupper($code)) {
            'PAYER_NOT_FOUND' => 'The number is not registered for mobile money.',
            'NOT_ENOUGH_FUNDS' => 'The customer does not have enough balance.',
            'PAYER_LIMIT_REACHED' => 'The customer has hit a transaction or wallet limit.',
            'PAYER_REJECTION', 'APPROVAL_REJECTED' => 'The customer declined the prompt.',
            'EXPIRED', 'PAYER_DELAYED' => 'The prompt was not answered in time.',
            'PAYEE_NOT_ALLOWED_TO_RECEIVE' => 'The receiving account cannot accept this payment.',
            'INTERNAL_PROCESSING_ERROR' => 'MTN reported an internal error. Query the status again before retrying.',
            default => $code,
        };
    }

    private function unknown(CollectionRequest $request, string $reference, string $why, Delivery $delivery): Transaction
    {
        return new Transaction(
            status: PaymentStatus::Unknown,
            amount: $request->amount,
            reference: $reference,
            provider: $this->name(),
            payer: $request->payer,
            failureReason: $why,
            raw: ['error' => 'connection', 'detail' => $why, 'delivery' => $delivery->value],
            delivery: $delivery,
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->withToken($this->accessToken())
            ->withHeaders(['Ocp-Apim-Subscription-Key' => (string) $this->config['subscription_key']])
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson();
    }

    /**
     * Tokens last an hour. Cached a little short of that so a request never
     * starts with a token that expires mid-flight.
     */
    private function accessToken(): string
    {
        $key = 'mobile-money:mtn_momo:token:'.md5((string) $this->config['api_user']);

        return Cache::remember($key, now()->addMinutes(50), function (): string {
            $response = Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
                ->withBasicAuth((string) $this->config['api_user'], (string) $this->config['api_key'])
                ->withHeaders(['Ocp-Apim-Subscription-Key' => (string) $this->config['subscription_key']])
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->post('/collection/token/');

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw ProviderException::authentication($this->name(), $response->status(), $this->errorBody($response));
            }

            return $response->json('access_token');
        });
    }

    private function errorBody(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            return (string) ($json['message'] ?? $json['error'] ?? json_encode($json));
        }

        return substr($response->body(), 0, 500);
    }

    /** MTN rejects a non-UUID X-Reference-Id, so derive one deterministically. */
    private function asUuid(string $key): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return strtolower($key);
        }

        // UUID v5 style: same input always yields the same UUID, so
        // idempotency survives the conversion.
        $hash = sha1('mobile-money:mtn_momo:'.$key);

        return sprintf(
            '%08s-%04s-5%03s-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12),
        );
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'.' : $value;
    }

    private function defaultCurrency(): Currency
    {
        return Currency::from($this->config['currency'] ?? 'XOF');
    }

    /**
     * The X-Reference-Id this request will be sent with.
     *
     * Derived from the idempotency key rather than remembered, so a crash
     * before the call still leaves something to poll with.
     */
    public function handleFor(CollectionRequest $request): string
    {
        return $this->asUuid($request->idempotencyKey);
    }

    public function supportedCurrencies(): array
    {
        return array_map(
            static fn (string $c) => Currency::from($c),
            $this->config['currencies'] ?? [$this->config['currency'] ?? 'XOF'],
        );
    }

    public function supportedCountries(): array
    {
        return $this->config['countries'] ?? [];
    }

    public function supports(Currency $currency, string $country): bool
    {
        return in_array($currency, $this->supportedCurrencies(), true)
            && in_array(strtoupper($country), $this->supportedCountries(), true);
    }

    private function assertSupported(Currency $currency, string $country): void
    {
        if (! $this->supports($currency, $country)) {
            throw ProviderException::unsupported($this->name(), $currency->value, $country);
        }
    }
}
