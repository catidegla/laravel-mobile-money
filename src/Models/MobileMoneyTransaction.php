<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Models;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\Delivery;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The local record of a payment.
 *
 * Exists mostly so that a payment survives the provider being unreachable. If
 * a webhook never arrives and the process that started the payment is gone,
 * this row is the only thing that knows the payment is outstanding.
 *
 * @property string $reference
 * @property string $idempotency_key
 * @property string $provider
 * @property PaymentStatus $status
 * @property ?Delivery $delivery
 * @property int $amount_minor
 * @property Currency $currency
 */
class MobileMoneyTransaction extends Model
{
    protected $table = 'mobile_money_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'delivery' => Delivery::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'poll_attempts' => 'integer',
            'metadata' => 'array',
            'raw' => 'array',
            'next_poll_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            // Anyone holding this can forge a notification for the order.
            'notif_token' => 'encrypted',
        ];
    }

    /* --------------------------------------------------------------- state */

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function payer(): ?Msisdn
    {
        return $this->payer_msisdn ? Msisdn::parse($this->payer_msisdn) : null;
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /* ------------------------------------------------------------ creation */

    public static function fromRequest(CollectionRequest $request, string $provider): self
    {
        return new self([
            'reference' => $request->reference,
            'idempotency_key' => $request->idempotencyKey,
            'provider' => $provider,
            'status' => PaymentStatus::Claimed,
            'delivery' => Delivery::Unattempted,
            'amount_minor' => $request->amount->minorUnits,
            'currency' => $request->amount->currency,
            'payer_msisdn' => $request->payer->e164(),
            'payer_country' => $request->payer->country,
            'metadata' => $request->metadata,
        ]);
    }

    /**
     * Record the intent to pay before anyone calls a provider.
     *
     * The ordering is the point. Writing the row after the call means a crash
     * in between leaves no trace, and the retry looks like a first attempt.
     * Writing it after the call succeeds but marking it done immediately has
     * the same hole from the other side: a failure after the mark leaves an id
     * that reads as handled, so the redelivery is discarded and the payment is
     * lost. The row goes in first, in a state that says nothing came back yet.
     *
     * The unique index on idempotency_key is what makes this safe under
     * concurrency rather than merely tidy. Two requests carrying the same key
     * race to insert; the database picks one, the loser catches the violation
     * and reads the winner's row. Checking for an existing row first and
     * inserting if absent would leave a window between the two statements
     * wide enough for both to pass.
     */
    public static function claim(CollectionRequest $request, string $provider): self
    {
        $record = self::fromRequest($request, $provider);

        try {
            $record->save();

            return $record;
        } catch (UniqueConstraintViolationException) {
            // Somebody already holds this key. Theirs is the real row, and
            // whatever state it is in is the truth about this payment.
            return self::query()->where('idempotency_key', $request->idempotencyKey)->firstOrFail();
        }
    }

    /**
     * Note what our own side saw of an attempt to send this request.
     *
     * Separate from applyTransaction() because the two answer different
     * questions and can happen apart: a rejection tells us the provider has
     * the request while giving us nothing to fold into the payment's state.
     */
    public function recordDelivery(Delivery $delivery): self
    {
        $merged = ($this->delivery ?? Delivery::Unattempted)->strongest($delivery);

        if ($merged !== $this->delivery) {
            $this->delivery = $merged;
            $this->save();
        }

        return $this;
    }

    /**
     * The row as the shape the drivers return, for answering without a call.
     *
     * Used when a claim turns out to be already settled, where calling the
     * provider again would be a question we have the answer to.
     */
    public function toTransaction(): Transaction
    {
        return new Transaction(
            status: $this->status,
            amount: $this->money(),
            reference: $this->reference,
            provider: $this->provider,
            providerReference: $this->provider_reference,
            payer: $this->payer(),
            redirectUrl: $this->redirect_url,
            delivery: $this->delivery,
            failureCode: $this->failure_code,
            failureReason: $this->failure_reason,
            completedAt: $this->completed_at?->toDateTimeImmutable(),
            raw: (array) ($this->raw ?? []),
        );
    }

    /**
     * Fold a provider result into this row.
     *
     * Deliberately refuses to move a settled payment backwards. A late webhook
     * arriving after a poll already confirmed success must not reopen it, and
     * out of order delivery is normal.
     */
    public function applyTransaction(Transaction $transaction): self
    {
        // Merged rather than assigned, and merged first so it survives the
        // early return below. What the row has to remember is whether the
        // provider ever saw the request, so the strongest evidence across all
        // attempts wins and a later refused connection cannot unsay an earlier
        // delivery. A result carrying no observation, which is every status()
        // call, leaves it alone.
        if ($transaction->delivery !== null) {
            $this->delivery = ($this->delivery ?? Delivery::Unattempted)->strongest($transaction->delivery);
        }

        if ($this->status->isSettled() && ! $transaction->status->isSettled()) {
            // Record that we saw it, but do not change the outcome.
            $this->raw = ['superseded' => $transaction->jsonSerialize()] + ($this->raw ?? []);
            $this->save();

            return $this;
        }

        $this->status = $transaction->status;
        $this->provider_reference = $transaction->providerReference ?? $this->provider_reference;
        $this->redirect_url = $transaction->redirectUrl ?? $this->redirect_url;
        $this->failure_code = $transaction->failureCode;
        $this->failure_reason = $transaction->failureReason;
        $this->raw = $transaction->raw;

        if (isset($transaction->raw['notif_token']) && is_string($transaction->raw['notif_token'])) {
            $this->notif_token = $transaction->raw['notif_token'];
        }

        if ($transaction->status->isFinal()) {
            $this->completed_at = $transaction->completedAt
                ? CarbonImmutable::instance($transaction->completedAt)
                : CarbonImmutable::now();
            $this->next_poll_at = null;
        }

        $this->save();

        return $this;
    }

    /* ----------------------------------------------------- reconciliation */

    /**
     * Schedule the next status check using the configured backoff.
     *
     * Once the schedule is exhausted the row stops being polled, so a payment
     * nobody ever answered does not get queried forever.
     */
    public function scheduleNextPoll(): self
    {
        $schedule = (array) config('mobile-money.reconciliation.schedule', [30, 60, 120, 300, 600, 1800, 3600]);
        $attempt = $this->poll_attempts;

        $this->poll_attempts = $attempt + 1;

        $delay = $schedule[$attempt] ?? null;
        $giveUpAfter = (int) config('mobile-money.reconciliation.give_up_after', 86400);
        $age = CarbonImmutable::now()->diffInSeconds($this->created_at ?? CarbonImmutable::now(), true);

        $this->next_poll_at = ($delay === null || $age > $giveUpAfter)
            ? null
            : CarbonImmutable::now()->addSeconds((int) $delay);

        $this->save();

        return $this;
    }

    /* --------------------------------------------------------------- scopes */

    /**
     * Unsettled and due a status check.
     *
     * Claimed belongs here for the same reason it exists. A process that died
     * between the claim and the provider call leaves a row nobody is waiting
     * on, and the reconciler is the only thing left that will ask what became
     * of it.
     */
    public function scopeDueForReconciliation(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                PaymentStatus::Claimed->value,
                PaymentStatus::Pending->value,
                PaymentStatus::Unknown->value,
            ])
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', CarbonImmutable::now());
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Succeeded->value);
    }
}
