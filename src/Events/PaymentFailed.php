<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Events;

use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The payment will not complete. Check \->status to tell a decline from a timeout from a technical failure, they need different handling.
 */
class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MobileMoneyTransaction $record,
        public readonly Transaction $transaction,
    ) {}
}
