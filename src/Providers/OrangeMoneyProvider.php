<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Providers;

use Catidegla\MobileMoney\Contracts\Provider;
use Catidegla\MobileMoney\Contracts\VerifiesWebhooks;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Orange Money, Web Payment API.
 *
 * Redirect flow. Creating a payment returns a pay_token and a payment_url, and
 * the customer completes on Orange's page.
 *
 * Two things about this provider shape the driver.
 *
 * The country is part of the URL path rather than a parameter, and the sandbox
 * uses the literal slug "dev" with a test currency of OUV instead of XOF. A
 * configuration that works in sandbox therefore needs two edits to go live,
 * not one, which is worth knowing before launch day.
 *
 * More importantly, Orange does not sign callbacks. It returns a notif_token
 * when the payment is created and sends that same token back on the
 * notification, so verification means comparing it against the one you stored.
 * That requires a lookup this package cannot do on its own, so a resolver has
 * to be supplied. Without one, verification fails closed.
 */
final class OrangeMoneyProvider implements Provider, VerifiesWebhooks
{
    /** Orange's transaction status vocabulary. */
    private const STATUS_MAP = [
        'INITIATED' => PaymentStatus::Pending,
        'PENDING' => PaymentStatus::Pending,
        'SUCCESS' => PaymentStatus::Succeeded,
        'SUCCESSFUL' => PaymentStatus::Succeeded,
        'FAILED' => PaymentStatus::Failed,
        'FAILURE' => PaymentStatus::Failed,
        'CANCELLED' => PaymentStatus::Cancelled,
        'EXPIRED' => PaymentStatus::Expired,
    ];

    /** @var (Closure(string): ?string)|null */
    private ?Closure $notifTokenResolver = null;

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'orange_money';
    }

    /**
     * Teach the driver how to find the notif_token stored when a payment was
     * created, given its order id.
     *
     * Register this in a service provider:
     *
     *   MobileMoney::driver('orange_money')->resolveNotifTokenUsing(
     *       fn (string $orderId) => Payment::where('reference', $orderId)->value('notif_token'),
     *   );
     *
     * @param Closure(string): ?string $resolver
     */
    public function resolveNotifTokenUsing(Closure $resolver): self
    {
        $this->notifTokenResolver = $resolver;

        return $this;
    }

    public function collect(CollectionRequest $request): Transaction
    {
        $this->assertSupported($request->amount->currency, $request->payer->country);

        $payload = [
            'merchant_key' => (string) $this->config['merchant_key'],
            'currency' => $this->requestCurrency($request->amount->currency),
            'order_id' => $request->reference,
            // Orange expects a number here rather than a string, and XOF is
            // whole francs, so this is the integer unchanged.
            'amount' => (int) $request->amount->forProvider(),
            'return_url' => (string) $this->config['return_url'],
            'cancel_url' => (string) ($this->config['cancel_url'] ?? $this->config['return_url']),
            'notif_url' => (string) ($request->callbackUrl ?? $this->config['notif_url']),
            'lang' => (string) ($this->config['lang'] ?? 'fr'),
            'reference' => mb_substr($request->description ?? $request->reference, 0, 40),
        ];

        try {
            $response = $this->client()->post($this->path('webpayment'), $payload);
        } catch (ConnectionException $e) {
            return new Transaction(
                status: PaymentStatus::Unknown,
                amount: $request->amount,
                reference: $request->reference,
                provider: $this->name(),
                payer: $request->payer,
                failureReason: $e->getMessage(),
                raw: ['error' => 'connection'],
            );
        }

        if (! $response->successful()) {
            throw ProviderException::rejected($this->name(), $response->status(), $this->errorBody($response));
        }

        $body = $response->json();

        if (! is_string($body['payment_url'] ?? null)) {
            throw ProviderException::rejected(
                $this->name(),
                $response->status(),
                'no payment_url in the response: '.json_encode($body),
            );
        }

        return new Transaction(
            status: PaymentStatus::Pending,
            amount: $request->amount,
            reference: $request->reference,
            provider: $this->name(),
            providerReference: $body['pay_token'] ?? null,
            payer: $request->payer,
            redirectUrl: $body['payment_url'],
            // notif_token must be persisted. It is the only thing that makes a
            // later callback verifiable.
            raw: $body,
        );
    }

    /**
     * Orange's status endpoint needs the amount and pay_token as well as the
     * order id, so a bare reference is not enough.
     *
     * Pass "orderId|amount|payToken" when calling through the generic
     * interface, or use statusFor() directly.
     */
    public function status(string $reference): Transaction
    {
        $parts = explode('|', $reference);

        if (count($parts) !== 3) {
            throw ProviderException::rejected(
                $this->name(),
                0,
                'Orange Money needs the order id, amount and pay_token to read a status. '.
                'Call statusFor($orderId, $amount, $payToken), or pass "orderId|amount|payToken".',
            );
        }

        [$orderId, $amount, $payToken] = $parts;

        return $this->statusFor($orderId, Money::ofMinor((int) $amount, $this->defaultCurrency()), $payToken);
    }

    public function statusFor(string $orderId, Money $amount, string $payToken): Transaction
    {
        try {
            $response = $this->client()->post($this->path('transactionstatus'), [
                'order_id' => $orderId,
                'amount' => (int) $amount->forProvider(),
                'pay_token' => $payToken,
            ]);
        } catch (ConnectionException $e) {
            return new Transaction(
                status: PaymentStatus::Unknown,
                amount: $amount,
                reference: $orderId,
                provider: $this->name(),
                providerReference: $payToken,
                failureReason: $e->getMessage(),
            );
        }

        if (! $response->successful()) {
            throw ProviderException::rejected($this->name(), $response->status(), $this->errorBody($response));
        }

        $body = $response->json();
        $status = $this->mapStatus((string) ($body['status'] ?? ''));

        return new Transaction(
            status: $status,
            amount: $amount,
            reference: $orderId,
            provider: $this->name(),
            providerReference: $body['txnid'] ?? $payToken,
            failureCode: $status->isFailure() ? (string) ($body['status'] ?? null) : null,
            failureReason: $status->isFailure() ? ($body['message'] ?? null) : null,
            completedAt: $status->isFinal() ? new DateTimeImmutable() : null,
            raw: is_array($body) ? $body : [],
        );
    }

    private function mapStatus(string $status): PaymentStatus
    {
        return self::STATUS_MAP[strtoupper(trim($status))] ?? PaymentStatus::Unknown;
    }

    /* ------------------------------------------------------------ webhooks */

    /**
     * Orange sends the notif_token it issued at creation. Verification is
     * comparing it against the stored one for that order.
     *
     * This is weaker than a signature: the token is a bearer secret that
     * travels in the body, so it is only as safe as the transport. Serve the
     * notification URL over HTTPS and treat the token as a credential.
     */
    public function verifyWebhook(Request $request): bool
    {
        if ($this->notifTokenResolver === null) {
            // No way to check, so nothing is accepted. The alternative is an
            // endpoint that marks any order paid on request.
            return false;
        }

        $payload = $request->json()->all();

        $orderId = $payload['order_id'] ?? null;
        $presented = $payload['notif_token'] ?? null;

        if (! is_string($orderId) || ! is_string($presented) || $presented === '') {
            return false;
        }

        $expected = ($this->notifTokenResolver)($orderId);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    public function parseWebhook(Request $request): Transaction
    {
        $payload = $request->json()->all();
        $status = $this->mapStatus((string) ($payload['status'] ?? ''));
        $currency = $this->defaultCurrency();

        return new Transaction(
            status: $status,
            amount: Money::of((int) ($payload['amount'] ?? 0), $currency),
            reference: (string) ($payload['order_id'] ?? ''),
            provider: $this->name(),
            providerReference: $payload['txnid'] ?? null,
            failureCode: $status->isFailure() ? (string) ($payload['status'] ?? null) : null,
            completedAt: $status->isFinal() ? new DateTimeImmutable() : null,
            raw: is_array($payload) ? $payload : [],
        );
    }

    /* ------------------------------------------------------------- plumbing */

    /**
     * The country slug sits in the path. "dev" is the sandbox, and a real
     * market uses its own slug, so this changes when you go live.
     */
    private function path(string $operation): string
    {
        $country = trim((string) ($this->config['country_slug'] ?? 'dev'), '/');

        return "/orange-money-webpay/{$country}/v1/{$operation}";
    }

    /**
     * The sandbox settles in OUV rather than the market currency, so tests
     * that pass in sandbox would fail in production without this override.
     */
    private function requestCurrency(Currency $currency): string
    {
        return (string) ($this->config['force_currency'] ?? $currency->value);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://api.orange.com'), '/'))
            ->withToken($this->accessToken())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson();
    }

    private function accessToken(): string
    {
        $key = 'mobile-money:orange_money:token:'.md5((string) $this->config['client_id']);

        return Cache::remember($key, now()->addMinutes(50), function (): string {
            $response = Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://api.orange.com'), '/'))
                ->withBasicAuth((string) $this->config['client_id'], (string) $this->config['client_secret'])
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->acceptJson()
                ->asForm()
                ->post((string) ($this->config['token_path'] ?? '/oauth/v3/token'), [
                    'grant_type' => 'client_credentials',
                ]);

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
            return (string) ($json['message'] ?? $json['description'] ?? $json['error'] ?? json_encode($json));
        }

        return substr($response->body(), 0, 500);
    }

    private function defaultCurrency(): Currency
    {
        return Currency::from($this->config['currency'] ?? 'XOF');
    }

    /** Orange answers on the order_id we send, which is our own reference. */
    public function handleFor(CollectionRequest $request): string
    {
        return $request->reference;
    }

    public function supportedCurrencies(): array
    {
        return array_map(
            static fn (string $c) => Currency::from($c),
            $this->config['currencies'] ?? ['XOF'],
        );
    }

    public function supportedCountries(): array
    {
        return $this->config['countries'] ?? ['CI', 'SN', 'ML', 'BF', 'CM', 'GN'];
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
