<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Models;

use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
            'status' => PaymentStatus::Pending,
            'amount_minor' => $request->amount->minorUnits,
            'currency' => $request->amount->currency,
            'payer_msisdn' => $request->payer->e164(),
            'payer_country' => $request->payer->country,
            'metadata' => $request->metadata,
        ]);
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

    /** Unsettled and due a status check. */
    public function scopeDueForReconciliation(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Unknown->value])
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
