<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Enums;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;

/**
 * What our own side observed about the outbound collection request.
 *
 * The reason this is worth storing separately from the payment's status: when
 * a provider later answers "I have no transaction with that reference", that
 * sentence has two possible meanings, and the provider does not say which.
 * Either it never received the request, or it received one it has not indexed
 * yet. Treating the first as the second strands a payment nobody will ever
 * settle; treating the second as the first fails a payment that is about to
 * succeed and invites a second charge.
 *
 * The provider cannot tell them apart for us. Our own HTTP client can. A
 * connection that was refused, or a host that never resolved, is proof the
 * request did not arrive, whatever the provider says afterwards. A read
 * timeout is proof of nothing, because the body may well have gone out and
 * only the answer got lost.
 *
 * So this records that observation at the moment it happens, while the
 * evidence exists, rather than trying to reconstruct it later from the age of
 * a row.
 */
enum Delivery: string
{
    /** Claimed, but nothing has been put on the wire yet. */
    case Unattempted = 'unattempted';

    /**
     * The connection never opened, so the provider cannot have seen it.
     *
     * Recorded only on positive evidence: a refused connection, a host that
     * did not resolve, a handshake that failed, or a timeout that expired
     * before the connection was established.
     */
    case NeverSent = 'never_sent';

    /**
     * The request went out and no usable answer came back.
     *
     * The state that has to stay ambiguous. A read timeout, a connection reset
     * mid-flight, a response we could not parse: the provider may have taken
     * the request and prompted the customer.
     */
    case Indeterminate = 'indeterminate';

    /** The provider answered, whatever the answer was. */
    case Delivered = 'delivered';

    /**
     * Read a failed HTTP attempt.
     *
     * Deliberately conservative, and asymmetric on purpose. NeverSent is only
     * returned on evidence that the connection did not open, and everything
     * else falls through to Indeterminate, because the cost of the two
     * mistakes is not the same: calling an arrived request "never sent" fails
     * a live payment, while calling a lost one "indeterminate" only means it
     * keeps being polled, which is what the reconciler already does.
     *
     * Two Guzzle generations answer this question in different places.
     *
     * Guzzle 8 sorts curl's errors into typed exceptions itself and dropped
     * getHandlerContext(), so the class is the answer: ConnectException is its
     * "the connection never opened" bucket, and ConnectTimeoutException, a
     * timeout that expired before one was established, extends it. Everything
     * that happened after the connection opened is a NetworkException or a
     * ResponseException instead, neither of which is a subclass of this one.
     *
     * Guzzle 7 has one ConnectException for all of it and puts curl's own
     * numbers on the side, so there the class proves nothing and the context
     * has to be read.
     *
     * The method_exists check rather than a version constant because what
     * matters is whether this object can answer, and a version comparison
     * would be one more thing to keep true.
     */
    public static function classify(ConnectionException $exception): self
    {
        $previous = $exception->getPrevious();

        if (! $previous instanceof ConnectException) {
            return self::Indeterminate;
        }

        return method_exists($previous, 'getHandlerContext')
            ? self::fromCurlContext((array) $previous->getHandlerContext())
            : self::NeverSent;
    }

    /**
     * Guzzle 7, where one exception class covers every connection failure.
     *
     * The curl numbers are the ones Guzzle itself treats as connection
     * failures. Written as literals because ext-curl is not guaranteed to be
     * loaded, and an undefined constant here would be a fatal error inside an
     * error path, which is the last place to put one.
     *
     * @param array<string, mixed> $context
     */
    private static function fromCurlContext(array $context): self
    {
        $errno = isset($context['errno']) ? (int) $context['errno'] : null;

        //  5 proxy host not resolved     7 connection refused
        //  6 host not resolved          35 TLS handshake failed
        if (in_array($errno, [5, 6, 7, 35], true)) {
            return self::NeverSent;
        }

        // 28 is a timeout, which is the interesting one. It covers both a
        // connection that never opened and a reply that never came back, and
        // only the first is conclusive. curl records when the connection was
        // established, so a connect_time of zero means there was never one.
        if ($errno === 28 && array_key_exists('connect_time', $context) && (float) $context['connect_time'] === 0.0) {
            return self::NeverSent;
        }

        return self::Indeterminate;
    }

    /**
     * The strongest evidence of arrival between two observations.
     *
     * A payment can be attempted more than once under one idempotency key, and
     * what the row has to remember is whether the provider ever saw it, not
     * what the latest attempt happened to do. One delivered attempt is enough
     * for the answer to be yes, permanently, and a later refused connection
     * does not unsay it.
     */
    public function strongest(self $other): self
    {
        return $other->certainty() > $this->certainty() ? $other : $this;
    }

    /**
     * Whether the provider cannot have received the request.
     *
     * True only for NeverSent, and only ever used together with the provider
     * saying it does not recognise the reference. Either half alone settles
     * nothing.
     */
    public function provesNeverArrived(): bool
    {
        return $this === self::NeverSent;
    }

    private function certainty(): int
    {
        return match ($this) {
            self::Unattempted => 0,
            self::NeverSent => 1,
            self::Indeterminate => 2,
            self::Delivered => 3,
        };
    }
}
