<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Data;

use Catidegla\MobileMoney\Enums\PaymentStatus;
use DateTimeImmutable;
use JsonSerializable;

/**
 * The state of a payment at a point in time.
 *
 * Returned by every provider operation, so calling code deals with one shape
 * regardless of which network the money moved on.
 */
final class Transaction implements JsonSerializable
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly Money $amount,
        /** Our reference, the one to poll with. */
        public readonly string $reference,
        public readonly string $provider,
        /** The provider's own identifier, where it differs from ours. */
        public readonly ?string $providerReference = null,
        public readonly ?Msisdn $payer = null,
        /** Where the customer must be sent to approve, for redirect-based providers. */
        public readonly ?string $redirectUrl = null,
        /** Provider error code, kept verbatim for support conversations. */
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly ?DateTimeImmutable $completedAt = null,
        /** @var array<string, mixed> the decoded provider payload, minus credentials */
        public readonly array $raw = [],
    ) {}

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * The customer has to be sent somewhere to complete this.
     *
     * True for Orange Money and Wave, false for MTN MoMo, which pushes a
     * prompt to the handset instead. Calling code has to branch on this
     * rather than assume one flow.
     */
    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null && ! $this->isFinal();
    }

    public function with(
        ?PaymentStatus $status = null,
        ?string $failureCode = null,
        ?string $failureReason = null,
        ?DateTimeImmutable $completedAt = null,
        ?array $raw = null,
    ): self {
        return new self(
            $status ?? $this->status,
            $this->amount,
            $this->reference,
            $this->provider,
            $this->providerReference,
            $this->payer,
            $this->redirectUrl,
            $failureCode ?? $this->failureCode,
            $failureReason ?? $this->failureReason,
            $completedAt ?? $this->completedAt,
            $raw ?? $this->raw,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference,
            'provider' => $this->provider,
            'provider_reference' => $this->providerReference,
            'status' => $this->status->value,
            'amount' => $this->amount->jsonSerialize(),
            'payer' => $this->payer?->e164(),
            'redirect_url' => $this->redirectUrl,
            'failure_code' => $this->failureCode,
            'failure_reason' => $this->failureReason,
            'completed_at' => $this->completedAt?->format(DATE_ATOM),
        ];
    }
}
