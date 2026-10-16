<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_Wallets')) {
            Schema::create('WBO_Wallets', function (Blueprint $table) {
                $table->integer('wallet_id', true);
                $table->integer('user_id')->unique();
                $table->decimal('balance', 15, 2)->default(0);
                $table->enum(
                    'status',
                    ['ACTIVE', 'FROZEN', 'CLOSED']
                )->default('ACTIVE');
                $table->timestamps();

                $table->foreign('user_id')
                    ->references('user_id')
                    ->on('WBO_Users')
                    ->cascadeOnDelete();

                $table->index(
                    ['status', 'user_id'],
                    'idx_wallet_status_user'
                );
            });
        }

        if (!Schema::hasTable('WBO_WalletTransactions')) {
            Schema::create(
                'WBO_WalletTransactions',
                function (Blueprint $table) {
                    $table->increments(
                        'wallet_transaction_id'
                    );
                    $table->integer('wallet_id');
                    $table->integer('user_id');
                    $table->integer('order_id')->nullable();

                    $table->enum('type', [
                        'TOP_UP',
                        'PURCHASE',
                        'REFUND',
                        'REVERSAL',
                        'ADJUSTMENT',
                    ]);

                    // Amount is always positive. The transaction type
                    // describes whether value enters or leaves the wallet.
                    $table->decimal('amount', 15, 2);

                    $table->decimal(
                        'balance_before',
                        15,
                        2
                    );
                    $table->decimal(
                        'balance_after',
                        15,
                        2
                    );

                    $table->string(
                        'payment_method',
                        40
                    )->nullable();

                    $table->string(
                        'reference_number',
                        100
                    )->nullable();

                    $table->enum(
                        'status',
                        ['COMPLETED', 'REVERSED']
                    )->default('COMPLETED');

                    $table->string(
                        'description',
                        255
                    )->nullable();

                    $table->timestamps();

                    $table->foreign('wallet_id')
                        ->references('wallet_id')
                        ->on('WBO_Wallets')
                        ->cascadeOnDelete();

                    $table->foreign('user_id')
                        ->references('user_id')
                        ->on('WBO_Users')
                        ->cascadeOnDelete();

                    $table->foreign('order_id')
                        ->references('order_id')
                        ->on('WBO_Orders')
                        ->nullOnDelete();

                    // Prevent a customer from crediting the same demo
                    // payment reference more than once.
                    $table->unique(
                        [
                            'user_id',
                            'payment_method',
                            'reference_number',
                        ],
                        'uq_wallet_user_payment_reference'
                    );

                    $table->index(
                        ['wallet_id', 'created_at'],
                        'idx_wallet_tx_wallet_created'
                    );

                    $table->index(
                        ['user_id', 'type', 'status'],
                        'idx_wallet_tx_user_type_status'
                    );

                    $table->index(
                        'order_id',
                        'idx_wallet_tx_order'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * Wallet balances and transaction history are financial records.
         * Never remove them automatically during a rollback.
         */
    }
};
