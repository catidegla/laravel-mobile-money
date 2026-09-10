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
    /**
     * Written before the provider was called, and not yet updated.
     *
     * This is the state a crash leaves behind. The row exists because we were
     * about to call, or were mid-call, so the provider may have received the
     * request and may have prompted the customer. Nothing has come back.
     *
     * The distinction from Unknown is where the doubt starts. Unknown means we
     * called and could not read the answer. Claimed means we may never have
     * finished asking. Both have to be queried before any retry, and neither
     * is safe to treat as a failure, which is the mistake that charges twice.
     */
    case Claimed = 'claimed';

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
            self::Claimed, self::Pending, self::Unknown => false,
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
            self::Claimed, self::Pending, self::Succeeded, self::Unknown => false,
        };
    }

    /**
     * Whether the provider has confirmed it has the request.
     *
     * False only for Claimed, and that is the whole reason the state exists.
     * Everything else in this enum describes something the provider told us.
     */
    public function isAcknowledged(): bool
    {
        return $this !== self::Claimed;
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }
}
