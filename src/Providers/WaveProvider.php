<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Providers;

use Catidegla\MobileMoney\Contracts\Provider;
use Catidegla\MobileMoney\Contracts\VerifiesWebhooks;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Wave, Checkout API.
 *
 * Redirect flow, unlike MTN. Creating a session returns a URL and the customer
 * finishes in the Wave app, so calling code has to send them there rather than
 * wait for a handset prompt.
 *
 * Wave reports two orthogonal statuses. checkout_status describes the session
 * (open, complete, expired) and payment_status describes the money
 * (processing, cancelled, succeeded). Reading only one of them loses
 * information: an expired session and a cancelled payment are different
 * outcomes and neither is a technical failure.
 *
 * Contract built from Wave's published Checkout and Webhook documentation.
 */
final class WaveProvider implements Provider, VerifiesWebhooks
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'wave';
    }

    public function collect(CollectionRequest $request): Transaction
    {
        $this->assertSupported($request->amount->currency, $request->payer->country);

        $payload = [
            'amount' => $request->amount->forProvider(),
            'currency' => $request->amount->currency->value,
            'success_url' => $this->config['success_url'] ?? throw ProviderException::rejected(
                $this->name(),
                0,
                'wave requires a success_url. Set it in config/mobile-money.php.',
            ),
            'error_url' => $this->config['error_url'] ?? $this->config['success_url'],
            // Wave caps this at 255 characters and echoes it back on the
            // webhook, which is how a callback is matched to an order.
            'client_reference' => mb_substr($request->reference, 0, 255),
            // Binds the session to one number, so a leaked checkout URL cannot
            // be paid by somebody else and credited to this order.
            'restrict_payer_mobile' => $request->payer->e164(),
        ];

        try {
            // Wave has no dedicated idempotency header, so the client reference
            // is the reconciliation handle. A repeated call creates a second
            // session, which is why status() can search by reference.
            $response = $this->client()->post('/v1/checkout/sessions', $payload);
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

        return $this->toTransaction($response->json(), $request->payer);
    }

    public function status(string $reference): Transaction
    {
        // Session ids are prefixed cos-. Anything else is treated as our own
        // client reference and looked up by search.
        $path = str_starts_with($reference, 'cos-')
            ? '/v1/checkout/sessions/'.$reference
            : '/v1/checkout/sessions/search?client_reference='.urlencode($reference);

        try {
            $response = $this->client()->get($path);
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

        $body = $response->json();

        // The search endpoint answers with a collection.
        if (isset($body['result']) && is_array($body['result'])) {
            $body = $body['result'][0] ?? throw ProviderException::notFound($this->name(), $reference);
        }

        return $this->toTransaction($body);
    }

    /** @param array<string, mixed> $session */
    private function toTransaction(array $session, ?Msisdn $payer = null): Transaction
    {
        $currency = Currency::tryFrom((string) ($session['currency'] ?? '')) ?? $this->defaultCurrency();

        $amount = isset($session['amount'])
            ? Money::of($session['amount'], $currency)
            : Money::ofMinor(0, $currency);

        $status = $this->mapStatus(
            (string) ($session['checkout_status'] ?? ''),
            (string) ($session['payment_status'] ?? ''),
        );

        $error = $session['last_payment_error'] ?? null;

        $completedAt = null;
        if (is_string($session['when_completed'] ?? null)) {
            $completedAt = new DateTimeImmutable($session['when_completed']);
        }

        return new Transaction(
            status: $status,
            amount: $amount,
            reference: (string) ($session['id'] ?? ''),
            provider: $this->name(),
            providerReference: $session['transaction_id'] ?? null,
            payer: $payer,
            redirectUrl: $session['wave_launch_url'] ?? null,
            failureCode: is_array($error) ? ($error['code'] ?? null) : null,
            failureReason: is_array($error) ? ($error['message'] ?? null) : null,
            completedAt: $completedAt,
            raw: $session,
        );
    }

    /**
     * Wave's two status fields together.
     *
     * payment_status is authoritative once it is decisive. checkout_status
     * only decides the outcome while the payment is still processing, because
     * that is when a session can quietly expire.
     */
    private function mapStatus(string $checkout, string $payment): PaymentStatus
    {
        return match ($payment) {
            'succeeded' => PaymentStatus::Succeeded,
            'cancelled' => PaymentStatus::Cancelled,
            'processing' => match ($checkout) {
                'expired' => PaymentStatus::Expired,
                'complete' => PaymentStatus::Pending,
                'open' => PaymentStatus::Pending,
                default => PaymentStatus::Pending,
            },
            default => match ($checkout) {
                'expired' => PaymentStatus::Expired,
                'complete' => PaymentStatus::Succeeded,
                'open' => PaymentStatus::Pending,
                default => PaymentStatus::Unknown,
            },
        };
    }

    /* ------------------------------------------------------------ webhooks */

    /**
     * Verify a Wave callback.
     *
     * Wave signs the timestamp concatenated directly to the raw body, with no
     * separator. The raw body matters: re-encoding the parsed JSON can reorder
     * keys and the signature will not match.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        $header = $request->header('Wave-Signature');
        if (! is_string($header) || $header === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parseSignatureHeader($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $tolerance = (int) ($this->config['webhook_tolerance'] ?? 300);
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            // A captured request replayed later must not be accepted.
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.$request->getContent(), $secret);

        foreach ($signatures as $candidate) {
            // Constant time, so a timing side channel cannot be used to forge
            // a signature byte by byte.
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "t=1639081943,v1=abc,v1=def"
     *
     * More than one v1 appears while a signing key is being rotated, and both
     * are valid during the overlap.
     *
     * @return array{0: int|null, 1: string[]}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }

    public function parseWebhook(Request $request): Transaction
    {
        $payload = $request->json()->all();
        $session = $payload['data'] ?? [];

        $transaction = $this->toTransaction(is_array($session) ? $session : []);

        // payment_failed carries the reason outside the session object.
        if (($payload['type'] ?? '') === 'checkout.session.payment_failed') {
            $error = $session['last_payment_error'] ?? [];

            return $transaction->with(
                status: PaymentStatus::Failed,
                failureCode: $error['code'] ?? 'payment_failed',
                failureReason: $error['message'] ?? 'Wave reported the payment failed.',
            );
        }

        return $transaction;
    }

    /* -------------------------------------------------------------- plumbing */

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://api.wave.com'), '/'))
            ->withToken((string) $this->config['api_key'])
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson();
    }

    private function errorBody(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $details = $json['details'] ?? null;

            return (string) ($json['message'] ?? $json['error_message'] ?? (is_string($details) ? $details : json_encode($json)));
        }

        return substr($response->body(), 0, 500);
    }

    private function defaultCurrency(): Currency
    {
        return Currency::from($this->config['currency'] ?? 'XOF');
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
        return $this->config['countries'] ?? ['SN', 'CI'];
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
