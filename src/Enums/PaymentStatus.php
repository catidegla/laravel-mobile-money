<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Enums;

/**
 * Where a payment currently stands.
 *
 * Mobile money is asynchronous in a way card payments are not. Initiating a
 * collection does not take money, it sends a prompt to a handset. The customer
 * then finds their phone, reads the prompt and types a PIN, or does not. The
 * gap between "requested" and "settled" is measured in minutes and frequently
 * never closes.
 *
 * Unknown is the state that matters most and the one most libraries omit. If a
 * request times out you do not know whether the provider received it. Treating
 * that as failed and retrying is how customers get charged twice.
 */
enum PaymentStatus: string
{
    /** Accepted by the provider. The customer has not acted yet. */
    case Pending = 'pending';

    /** Money moved. The only state safe to fulfil an order on. */
    case Succeeded = 'succeeded';

    /** Provider gave a definitive negative: wrong PIN, insufficient balance, blocked account. */
    case Failed = 'failed';

    /** The customer actively declined the prompt. */
    case Cancelled = 'cancelled';

    /** The prompt was never answered and the provider closed it. */
    case Expired = 'expired';

    /**
     * We could not establish what happened. A timeout, an unreachable
     * provider, or a response we could not interpret.
     *
     * Never retry a payment in this state without first querying it by its
     * idempotency key.
     */
    case Unknown = 'unknown';

    /** Reached a state that will not change on its own. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled, self::Expired => true,
            self::Pending, self::Unknown => false,
        };
    }

    /** Worth polling again. */
    public function isPollable(): bool
    {
        return ! $this->isFinal();
    }

    /** Safe to release goods or credit an account. */
    public function isSettled(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether starting a fresh payment for the same order is safe.
     *
     * Deliberately false for Unknown. The whole point of that state is that a
     * retry might be a double charge.
     */
    public function allowsRetry(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Expired => true,
            self::Pending, self::Succeeded, self::Unknown => false,
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }
}
