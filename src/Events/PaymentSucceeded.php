<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Events;

use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money has moved. The only event safe to fulfil an order on.
 */
class PaymentSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MobileMoneyTransaction $record,
        public readonly Transaction $transaction,
    ) {}
}
