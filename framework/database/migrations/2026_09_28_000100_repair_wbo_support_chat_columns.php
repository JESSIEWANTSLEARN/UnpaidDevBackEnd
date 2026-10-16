<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_Conversations')) {
            throw new RuntimeException('WBO_Conversations is missing. Run the Stage 4 support-chat migration first.');
        }

        $conversationColumns = [
            'assigned_user_id' => Schema::hasColumn('WBO_Conversations', 'assigned_user_id'),
            'subject' => Schema::hasColumn('WBO_Conversations', 'subject'),
            'status' => Schema::hasColumn('WBO_Conversations', 'status'),
            'created_at' => Schema::hasColumn('WBO_Conversations', 'created_at'),
            'updated_at' => Schema::hasColumn('WBO_Conversations', 'updated_at'),
            'closed_at' => Schema::hasColumn('WBO_Conversations', 'closed_at'),
        ];

        Schema::table('WBO_Conversations', function (Blueprint $table) use ($conversationColumns) {
            if (!$conversationColumns['assigned_user_id']) {
                $table->integer('assigned_user_id')->nullable();
            }

            if (!$conversationColumns['subject']) {
                $table->string('subject', 150)->nullable();
            }

            if (!$conversationColumns['status']) {
                $table->enum('status', ['BOT', 'WAITING_STAFF', 'ACTIVE', 'CLOSED'])
                    ->default('BOT');
            }

            if (!$conversationColumns['created_at']) {
                $table->timestamp('created_at')->nullable();
            }

            if (!$conversationColumns['updated_at']) {
                $table->timestamp('updated_at')->nullable();
            }

            if (!$conversationColumns['closed_at']) {
                $table->dateTime('closed_at')->nullable();
            }
        });

        if (Schema::hasTable('WBO_ConversationMessages')) {
            $messageColumns = [
                'conversation_id' => Schema::hasColumn('WBO_ConversationMessages', 'conversation_id'),
                'sender_user_id' => Schema::hasColumn('WBO_ConversationMessages', 'sender_user_id'),
                'sender_type' => Schema::hasColumn('WBO_ConversationMessages', 'sender_type'),
                'message' => Schema::hasColumn('WBO_ConversationMessages', 'message'),
                'created_at' => Schema::hasColumn('WBO_ConversationMessages', 'created_at'),
            ];

            Schema::table('WBO_ConversationMessages', function (Blueprint $table) use ($messageColumns) {
                if (!$messageColumns['conversation_id']) {
                    $table->unsignedBigInteger('conversation_id')->nullable();
                }

                if (!$messageColumns['sender_user_id']) {
                    $table->integer('sender_user_id')->nullable();
                }

                if (!$messageColumns['sender_type']) {
                    $table->enum('sender_type', ['CUSTOMER', 'BOT', 'STAFF', 'SYSTEM'])
                        ->default('SYSTEM');
                }

                if (!$messageColumns['message']) {
                    $table->text('message')->nullable();
                }

                if (!$messageColumns['created_at']) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('WBO_ConversationTransfers')) {
            $transferColumns = [
                'conversation_id' => Schema::hasColumn('WBO_ConversationTransfers', 'conversation_id'),
                'from_user_id' => Schema::hasColumn('WBO_ConversationTransfers', 'from_user_id'),
                'to_user_id' => Schema::hasColumn('WBO_ConversationTransfers', 'to_user_id'),
                'transfer_type' => Schema::hasColumn('WBO_ConversationTransfers', 'transfer_type'),
                'note' => Schema::hasColumn('WBO_ConversationTransfers', 'note'),
                'created_at' => Schema::hasColumn('WBO_ConversationTransfers', 'created_at'),
            ];

            Schema::table('WBO_ConversationTransfers', function (Blueprint $table) use ($transferColumns) {
                if (!$transferColumns['conversation_id']) {
                    $table->unsignedBigInteger('conversation_id')->nullable();
                }

                if (!$transferColumns['from_user_id']) {
                    $table->integer('from_user_id')->nullable();
                }

                if (!$transferColumns['to_user_id']) {
                    $table->integer('to_user_id')->nullable();
                }

                if (!$transferColumns['transfer_type']) {
                    $table->enum('transfer_type', ['ESCALATED', 'ASSIGNED', 'REASSIGNED', 'CLOSED'])
                        ->default('ESCALATED');
                }

                if (!$transferColumns['note']) {
                    $table->string('note', 255)->nullable();
                }

                if (!$transferColumns['created_at']) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally no destructive rollback.
        // This migration only repairs columns missing from an older/local schema.
    }
};