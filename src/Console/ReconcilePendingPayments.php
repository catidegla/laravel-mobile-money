<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Console;

use Catidegla\MobileMoney\Events\PaymentFailed;
use Catidegla\MobileMoney\Events\PaymentSucceeded;
use Catidegla\MobileMoney\MobileMoneyManager;
use Catidegla\MobileMoney\Models\MobileMoneyTransaction;
use Catidegla\MobileMoney\Providers\OrangeMoneyProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Polls payments that have not reached a final state.
 *
 * This is not a fallback, it is the primary path. Callbacks in this region are
 * lost often enough that a system relying on them alone will strand orders,
 * and the customer whose money left their wallet will not accept "we did not
 * get the notification" as an answer.
 *
 * Schedule it every minute. The backoff lives on each row, so running often
 * costs nothing for payments that are not due.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'mobile-money:reconcile
        {--provider= : Only reconcile one provider}
        {--limit=100 : Maximum transactions per run}';

    protected $description = 'Query the provider for payments that have not settled yet';

    public function handle(MobileMoneyManager $manager): int
    {
        if (! config('mobile-money.reconciliation.enabled', true)) {
            $this->comment('Reconciliation is disabled in config.');

            return self::SUCCESS;
        }

        $query = MobileMoneyTransaction::query()->dueForReconciliation();

        if ($provider = $this->option('provider')) {
            $query->forProvider($provider);
        }

        $due = $query->orderBy('next_poll_at')->limit((int) $this->option('limit'))->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $settled = 0;
        $failed = 0;
        $stillOpen = 0;
        $errored = 0;

        foreach ($due as $record) {
            try {
                $driver = $manager->driver($record->provider);

                // Orange needs the amount and pay_token as well as the order
                // id, so it cannot use the generic single-argument call.
                $result = $driver instanceof OrangeMoneyProvider
                    ? $driver->statusFor($record->reference, $record->money(), (string) $record->provider_reference)
                    : $driver->status((string) ($record->provider_reference ?: $record->reference));
            } catch (Throwable $e) {
                // One unreachable provider must not stop the run.
                $errored++;
                $record->scheduleNextPoll();

                $this->warn("{$record->reference}: {$e->getMessage()}");

                continue;
            }

            $wasSettled = $record->isSettled();
            $record->applyTransaction($result);

            if (! $wasSettled && $record->isSettled()) {
                PaymentSucceeded::dispatch($record, $result);
                $settled++;
            } elseif (! $wasSettled && $record->status->isFailure()) {
                PaymentFailed::dispatch($record, $result);
                $failed++;
            } else {
                // Still pending, or still unknown. Try again later.
                $record->scheduleNextPoll();
                $stillOpen++;
            }
        }

        $this->info(sprintf(
            'Checked %d: %d settled, %d failed, %d still open, %d could not be reached.',
            $due->count(),
            $settled,
            $failed,
            $stillOpen,
            $errored,
        ));

        return self::SUCCESS;
    }
}
