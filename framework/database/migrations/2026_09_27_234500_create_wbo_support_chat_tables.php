<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_Conversations')) {
            Schema::create('WBO_Conversations', function (Blueprint $table) {
                $table->bigIncrements('conversation_id');
                $table->integer('customer_user_id');
                $table->integer('assigned_user_id')->nullable();
                $table->string('subject', 150)->nullable();
                $table->enum('status', ['BOT', 'WAITING_STAFF', 'ACTIVE', 'CLOSED'])->default('BOT');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->dateTime('closed_at')->nullable();

                $table->foreign('customer_user_id', 'fk_wbo_conv_customer')
                    ->references('user_id')->on('WBO_Users')->cascadeOnDelete();
                $table->foreign('assigned_user_id', 'fk_wbo_conv_staff')
                    ->references('user_id')->on('WBO_Users')->nullOnDelete();

                $table->index(['customer_user_id', 'status'], 'idx_wbo_conv_customer_status');
                $table->index(['assigned_user_id', 'status'], 'idx_wbo_conv_staff_status');
            });
        }

        if (!Schema::hasTable('WBO_ConversationMessages')) {
            Schema::create('WBO_ConversationMessages', function (Blueprint $table) {
                $table->bigIncrements('message_id');
                $table->unsignedBigInteger('conversation_id');
                $table->integer('sender_user_id')->nullable();
                $table->enum('sender_type', ['CUSTOMER', 'BOT', 'STAFF', 'SYSTEM']);
                $table->text('message');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('conversation_id', 'fk_wbo_msg_conv')
                    ->references('conversation_id')->on('WBO_Conversations')->cascadeOnDelete();
                $table->foreign('sender_user_id', 'fk_wbo_msg_sender')
                    ->references('user_id')->on('WBO_Users')->nullOnDelete();

                $table->index(['conversation_id', 'created_at'], 'idx_wbo_msg_conv_created');
            });
        }

        if (!Schema::hasTable('WBO_ConversationTransfers')) {
            Schema::create('WBO_ConversationTransfers', function (Blueprint $table) {
                $table->bigIncrements('transfer_id');
                $table->unsignedBigInteger('conversation_id');
                $table->integer('from_user_id')->nullable();
                $table->integer('to_user_id')->nullable();
                $table->enum('transfer_type', ['ESCALATED', 'ASSIGNED', 'REASSIGNED', 'CLOSED']);
                $table->string('note', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('conversation_id', 'fk_wbo_transfer_conv')
                    ->references('conversation_id')->on('WBO_Conversations')->cascadeOnDelete();
                $table->foreign('from_user_id', 'fk_wbo_transfer_from')
                    ->references('user_id')->on('WBO_Users')->nullOnDelete();
                $table->foreign('to_user_id', 'fk_wbo_transfer_to')
                    ->references('user_id')->on('WBO_Users')->nullOnDelete();

                $table->index(['conversation_id', 'created_at'], 'idx_wbo_transfer_conv_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('WBO_ConversationTransfers');
        Schema::dropIfExists('WBO_ConversationMessages');
        Schema::dropIfExists('WBO_Conversations');
    }
};