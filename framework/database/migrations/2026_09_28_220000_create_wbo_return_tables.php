<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_ReturnRequests')) {
            Schema::create(
                'WBO_ReturnRequests',
                function (Blueprint $table) {
                    $table->bigIncrements('return_id');
                    $table->integer('order_id');
                    $table->integer('customer_user_id');
                    $table->enum('status', [
                        'REQUESTED',
                        'APPROVED',
                        'REJECTED',
                        'RECEIVED_FOR_INSPECTION',
                        'REFUND_PENDING',
                        'REFUNDED',
                    ])->default('REQUESTED');
                    $table->string('reason', 500);
                    $table->enum(
                        'inspection_disposition',
                        [
                            'RESTOCK',
                            'QUARANTINE',
                            'WRITE_OFF',
                            'RETURN_TO_SUPPLIER',
                        ]
                    )->nullable();
                    $table->string(
                        'inspection_notes',
                        500
                    )->nullable();
                    $table->decimal(
                        'refund_amount',
                        12,
                        2
                    )->default(0);
                    $table->integer(
                        'handled_by_user_id'
                    )->nullable();
                    $table->dateTime(
                        'requested_at'
                    );
                    $table->dateTime(
                        'approved_at'
                    )->nullable();
                    $table->dateTime(
                        'received_at'
                    )->nullable();
                    $table->dateTime(
                        'inspected_at'
                    )->nullable();
                    $table->dateTime(
                        'refunded_at'
                    )->nullable();
                    $table->timestamps();

                    $table->unique(
                        'order_id',
                        'uq_wbo_return_order'
                    );

                    $table->foreign(
                        'order_id',
                        'fk_wbo_return_order'
                    )
                        ->references('order_id')
                        ->on('WBO_Orders')
                        ->restrictOnDelete();

                    $table->foreign(
                        'customer_user_id',
                        'fk_wbo_return_customer'
                    )
                        ->references('user_id')
                        ->on('WBO_Users')
                        ->restrictOnDelete();

                    $table->foreign(
                        'handled_by_user_id',
                        'fk_wbo_return_handler'
                    )
                        ->references('user_id')
                        ->on('WBO_Users')
                        ->nullOnDelete();

                    $table->index(
                        'status',
                        'idx_wbo_return_status'
                    );
                    $table->index(
                        'customer_user_id',
                        'idx_wbo_return_customer'
                    );
                }
            );
        }

        if (!Schema::hasTable('WBO_ReturnItems')) {
            Schema::create(
                'WBO_ReturnItems',
                function (Blueprint $table) {
                    $table->bigIncrements(
                        'return_item_id'
                    );
                    $table->unsignedBigInteger(
                        'return_id'
                    );
                    $table->integer(
                        'order_detail_id'
                    );
                    $table->integer(
                        'product_id'
                    );
                    $table->integer('quantity');
                    $table->decimal(
                        'unit_price',
                        10,
                        2
                    );

                    $table->foreign(
                        'return_id',
                        'fk_wbo_return_item_return'
                    )
                        ->references('return_id')
                        ->on('WBO_ReturnRequests')
                        ->cascadeOnDelete();

                    $table->foreign(
                        'order_detail_id',
                        'fk_wbo_return_item_detail'
                    )
                        ->references(
                            'order_detail_id'
                        )
                        ->on('WBO_OrderDetails')
                        ->restrictOnDelete();

                    $table->foreign(
                        'product_id',
                        'fk_wbo_return_item_product'
                    )
                        ->references('product_id')
                        ->on('WBO_Products')
                        ->restrictOnDelete();

                    $table->unique(
                        [
                            'return_id',
                            'order_detail_id',
                        ],
                        'uq_wbo_return_detail'
                    );
                    $table->index(
                        'product_id',
                        'idx_wbo_return_item_product'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('WBO_ReturnItems');
        Schema::dropIfExists('WBO_ReturnRequests');
    }
};