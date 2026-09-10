<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Console;

use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Catidegla\MobileMoney\Events\PaymentFailed;
use Catidegla\MobileMoney\Events\PaymentSucceeded;
use Catidegla\MobileMoney\Exceptions\ProviderException;
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
        $unrecognised = 0;

        foreach ($due as $record) {
            try {
                $driver = $manager->driver($record->provider);

                // Orange needs the amount and pay_token as well as the order
                // id, so it cannot use the generic single-argument call.
                $result = $driver instanceof OrangeMoneyProvider
                    ? $driver->statusFor($record->reference, $record->money(), (string) $record->provider_reference)
                    : $driver->status((string) ($record->provider_reference ?: $record->reference));
            } catch (ProviderException $e) {
                // A provider saying it has never heard of the reference means
                // one of two things and does not say which: it never received
                // the request, or it received one it has not indexed yet.
                //
                // On its own that is not something to act on, and this command
                // used to stop there. What settles it is the other half of the
                // evidence, which is on our side rather than theirs. If the
                // connection carrying that request never opened, the provider
                // cannot be holding it, and the two facts together say the
                // payment does not exist rather than that it is slow.
                if ($e->isNotFound() && $record->delivery?->provesNeverArrived()) {
                    $closed = $this->neverArrived($record);
                    $record->applyTransaction($closed);
                    PaymentFailed::dispatch($record, $closed);
                    $failed++;
                    $this->warn("{$record->reference}: never sent and unknown to {$record->provider}, closed");

                    continue;
                }

                $record->scheduleNextPoll();

                if ($e->isNotFound()) {
                    $unrecognised++;
                    $this->warn("{$record->reference}: not recognised by {$record->provider}, still polling");
                } else {
                    $errored++;
                    $this->warn("{$record->reference}: {$e->getMessage()}");
                }

                continue;
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

        // Rows closed as never sent are counted under failed rather than given
        // a category of their own. From the order's point of view they are the
        // same event, and why it happened is on the row.
        $this->info(sprintf(
            'Checked %d: %d settled, %d failed, %d still open, %d could not be reached, %d not recognised.',
            $due->count(),
            $settled,
            $failed,
            $stillOpen,
            $errored,
            $unrecognised,
        ));

        return self::SUCCESS;
    }

    /**
     * The result for a payment we can show was never sent.
     *
     * Failed rather than Unknown, and that is the whole point of the column it
     * rests on. Unknown exists because a retry might be a second charge, so it
     * blocks one; here there is nothing to charge twice, because the request
     * never left this process and the provider has confirmed it is holding
     * nothing. Closing it releases the order to be paid again.
     */
    private function neverArrived(MobileMoneyTransaction $record): Transaction
    {
        return new Transaction(
            status: PaymentStatus::Failed,
            amount: $record->money(),
            reference: $record->reference,
            provider: $record->provider,
            providerReference: $record->provider_reference,
            payer: $record->payer(),
            failureCode: 'never_sent',
            failureReason: sprintf(
                'The request never left this process, and %s does not recognise the reference. '.
                'No payment was created, so this order can be attempted again.',
                $record->provider,
            ),
            raw: ['closed_by' => 'reconciler', 'delivery' => $record->delivery?->value],
        );
    }
}
