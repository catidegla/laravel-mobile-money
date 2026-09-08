<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Contracts;

use Catidegla\MobileMoney\Data\Transaction;
use Illuminate\Http\Request;

/**
 * A provider that signs its callbacks.
 *
 * Implementations must verify before parsing, and must compare signatures in
 * constant time. An unverified callback endpoint is a public API for marking
 * any order paid, which is the single most damaging bug a payment integration
 * can ship.
 */
interface VerifiesWebhooks
{
    /**
     * Confirm the request genuinely came from the provider.
     *
     * Must return false rather than throwing on a malformed request, so the
     * controller can answer with one status code for every rejection and avoid
     * telling an attacker which part they got wrong.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Turn a verified callback into a transaction.
     *
     * Only ever called after verifyWebhook returned true.
     */
    public function parseWebhook(Request $request): Transaction;
}
