<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_Orders')) {
            return;
        }

        if (!Schema::hasColumn('WBO_Orders', 'checkout_token')) {
            Schema::table('WBO_Orders', function (Blueprint $table) {
                $table->string('checkout_token', 64)
                    ->nullable()
                    ->after('paid_at');
            });
        }

        $indexExists = collect(DB::select(
            "SHOW INDEX FROM WBO_Orders WHERE Key_name = 'uq_order_customer_checkout_token'"
        ))->isNotEmpty();

        if (!$indexExists) {
            Schema::table('WBO_Orders', function (Blueprint $table) {
                $table->unique(
                    ['customer_user_id', 'checkout_token'],
                    'uq_order_customer_checkout_token'
                );
            });
        }

        // Add WALLET without changing the existing payment values.
        DB::statement(
            "ALTER TABLE WBO_Orders MODIFY payment_method " .
            "ENUM('CASH_ON_DELIVERY','GCASH','BANK_TRANSFER','WALLET') NULL"
        );
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         * A WALLET order may already exist, so automatically shrinking the
         * enum or dropping checkout tokens could destroy financial history.
         */
    }
};
