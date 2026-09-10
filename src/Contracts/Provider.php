<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Contracts;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;

/**
 * What every provider must do.
 *
 * Deliberately small. Collections, status, and knowing your own limits are the
 * only universal operations. Payouts, refunds and webhook verification are
 * separate interfaces because support is genuinely uneven, and a base
 * interface that throws NotSupported for half its methods is a worse contract
 * than one you can ask.
 */
interface Provider
{
    /** Driver key, matching the config file. */
    public function name(): string;

    /**
     * Ask the customer to approve a payment.
     *
     * Returns as soon as the provider accepts the request, which is before the
     * customer has done anything, so the result is normally Pending. The
     * request carries an idempotency key: calling this twice with the same key
     * must not create a second charge.
     */
    public function collect(CollectionRequest $request): Transaction;

    /**
     * Current state of a transaction, by the reference returned at initiation.
     *
     * Must be safe to call repeatedly. This is the reconciliation path when a
     * webhook never arrives, which in this region is often.
     */
    public function status(string $reference): Transaction;

    /**
     * What status() will answer on for this request, known before calling.
     *
     * The point is the payment that never got a reply. A crash between writing
     * the local row and hearing back leaves nothing from the provider to poll
     * with, so the handle has to be derivable from what we already had.
     *
     * For most drivers that is simply the merchant reference they were given.
     * MTN is the exception worth the method existing: it answers on the
     * X-Reference-Id, which is a UUID derived deterministically from the
     * idempotency key, so the same key always yields the same handle and a
     * request that may never have been sent is still queryable.
     */
    public function handleFor(CollectionRequest $request): string;

    /** Currencies this driver can actually move, as configured. */
    public function supportedCurrencies(): array;

    /** ISO 3166-1 alpha-2 codes this driver serves. */
    public function supportedCountries(): array;

    public function supports(Currency $currency, string $country): bool;
}
