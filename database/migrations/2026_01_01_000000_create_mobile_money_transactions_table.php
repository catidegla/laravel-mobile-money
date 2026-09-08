<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_money_transactions', function (Blueprint $table): void {
            $table->id();

            // The merchant's own order identifier. Not unique on its own,
            // because a cancelled payment can legitimately be retried for the
            // same order.
            $table->string('reference')->index();

            // Unique per attempt. This is what makes a retry safe: a second
            // insert with the same key is a duplicate, not a second charge.
            $table->string('idempotency_key')->unique();

            $table->string('provider');
            $table->string('provider_reference')->nullable();

            $table->string('status')->index();

            // Integer minor units, never a decimal column. For XOF the minor
            // unit is the franc itself, so this holds whole francs.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            $table->string('payer_msisdn')->nullable();
            $table->string('payer_country', 2)->nullable();

            $table->text('redirect_url')->nullable();

            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();

            // Orange Money's callback secret. Encrypted at rest: anyone
            // holding it can forge a notification for this order.
            $table->text('notif_token')->nullable();

            $table->json('metadata')->nullable();
            $table->json('raw')->nullable();

            // Reconciliation. Webhooks in this region are not reliable enough
            // to be the only path to a final state.
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The reconciler's query: everything unsettled that is due.
            $table->index(['status', 'next_poll_at']);
            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_money_transactions');
    }
};
