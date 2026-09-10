<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_money_transactions', function (Blueprint $table): void {
            // What our own side saw of the outbound request. The provider
            // cannot tell us whether a reference it does not recognise is one
            // it never received or one it has not indexed yet, but our HTTP
            // client knows whether the connection ever opened. See the
            // Delivery enum for why that difference decides the payment.
            //
            // Rows written before this column existed keep the null, which
            // reads as "we did not record it" and is handled as such: nothing
            // is concluded from a reference the provider does not recognise
            // unless there is positive evidence behind it.
            $table->string('delivery')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_money_transactions', function (Blueprint $table): void {
            $table->dropColumn('delivery');
        });
    }
};
