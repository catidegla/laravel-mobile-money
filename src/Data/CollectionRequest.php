<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Data;

use Illuminate\Support\Str;

/**
 * A request to take money from a customer.
 *
 * The idempotency key is not optional and is generated here when the caller
 * does not supply one. Every provider in this package accepts a client
 * supplied identifier, and it is the only thing standing between a network
 * timeout and a double charge.
 */
final class CollectionRequest
{
    public function __construct(
        public readonly Money $amount,
        public readonly Msisdn $payer,
        /** The merchant's own identifier for this order. Shown on statements where supported. */
        public readonly string $reference,
        /**
         * Stable across retries of the same logical payment.
         *
         * Regenerating this for a retry defeats the entire mechanism, so it is
         * generated once at construction and carried through.
         */
        public readonly string $idempotencyKey = '',
        /** Shown to the customer on their handset, where the provider supports it. */
        public readonly ?string $description = null,
        /** Overrides the configured callback for this one payment. */
        public readonly ?string $callbackUrl = null,
        /** @var array<string, scalar> stored locally, not sent to the provider */
        public readonly array $metadata = [],
    ) {}

    public static function make(
        Money $amount,
        Msisdn $payer,
        string $reference,
        ?string $description = null,
        array $metadata = [],
    ): self {
        return new self(
            amount: $amount,
            payer: $payer,
            reference: $reference,
            idempotencyKey: (string) Str::uuid(),
            description: $description,
            metadata: $metadata,
        );
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self(
            $this->amount,
            $this->payer,
            $this->reference,
            $key,
            $this->description,
            $this->callbackUrl,
            $this->metadata,
        );
    }

    public function withCallbackUrl(?string $url): self
    {
        return new self(
            $this->amount,
            $this->payer,
            $this->reference,
            $this->idempotencyKey,
            $this->description,
            $url,
            $this->metadata,
        );
    }

    /** Never contains the amount as a float, and never a secret. */
    public function toLogContext(): array
    {
        return [
            'reference' => $this->reference,
            'idempotency_key' => $this->idempotencyKey,
            'amount_minor' => $this->amount->minorUnits,
            'currency' => $this->amount->currency->value,
            'payer_country' => $this->payer->country,
            // Last four digits only. A full MSISDN in a log is personal data.
            'payer_suffix' => substr($this->payer->national(), -4),
        ];
    }
}
