<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Http\Controllers;

use Catidegla\MobileMoney\Contracts\VerifiesWebhooks;
use Catidegla\MobileMoney\Events\PaymentFailed;
use Catidegla\MobileMoney\Events\PaymentSucceeded;
use Catidegla\MobileMoney\MobileMoneyManager;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives provider callbacks.
 *
 * Three rules shape this controller.
 *
 * Verify before reading. Nothing from the body is trusted, or even parsed
 * into a decision, until the provider's own verification passes.
 *
 * Answer the same way for every rejection. A callback that fails verification,
 * names an unknown provider, or refers to an order that does not exist all get
 * the same 202 and the same empty body. Distinguishing them tells an attacker
 * probing the endpoint which part they got wrong.
 *
 * Never fail loudly at the provider. Providers retry on a non-2xx and some
 * disable an endpoint that keeps erroring, so an internal exception is logged
 * and swallowed rather than returned.
 */
class WebhookController
{
    public function __construct(private readonly MobileMoneyManager $manager) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        try {
            $driver = $this->manager->driver($provider);
        } catch (Throwable) {
            return $this->accepted();
        }

        if (! $driver instanceof VerifiesWebhooks) {
            Log::warning('mobile-money: callback for a provider that cannot verify webhooks', [
                'provider' => $provider,
            ]);

            return $this->accepted();
        }

        if (! $driver->verifyWebhook($request)) {
            // Deliberately terse. The detail belongs in logs, not in a
            // response an attacker can read.
            Log::warning('mobile-money: webhook verification failed', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return $this->accepted();
        }

        try {
            $transaction = $driver->parseWebhook($request);
        } catch (Throwable $e) {
            Log::error('mobile-money: could not parse a verified webhook', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->accepted();
        }

        $record = MobileMoneyTransaction::query()
            ->where('provider', $provider)
            ->where(function ($query) use ($transaction): void {
                $query->where('reference', $transaction->reference)
                    ->orWhere('provider_reference', $transaction->reference);

                if ($transaction->providerReference !== null) {
                    $query->orWhere('provider_reference', $transaction->providerReference);
                }
            })
            ->first();

        if ($record === null) {
            Log::warning('mobile-money: verified webhook for an unknown transaction', [
                'provider' => $provider,
                'reference' => $transaction->reference,
            ]);

            return $this->accepted();
        }

        $wasSettled = $record->isSettled();
        $record->applyTransaction($transaction);

        // Fire only on the transition, so a provider retrying a callback does
        // not deliver the order twice.
        if (! $wasSettled && $record->isSettled()) {
            PaymentSucceeded::dispatch($record, $transaction);
        } elseif (! $wasSettled && $record->status->isFailure()) {
            PaymentFailed::dispatch($record, $transaction);
        }

        return $this->accepted();
    }

    /**
     * 202 for everything.
     *
     * The provider only needs to know the callback was received, and a
     * uniform answer keeps the endpoint from being used to enumerate orders.
     */
    private function accepted(): JsonResponse
    {
        return new JsonResponse(['received' => true], 202);
    }
}
