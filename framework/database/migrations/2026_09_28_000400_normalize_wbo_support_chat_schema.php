<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('WBO_Conversations') ||
            !Schema::hasTable('WBO_ConversationMessages') ||
            !Schema::hasTable('WBO_ConversationTransfers')
        ) {
            throw new \RuntimeException(
                'Support-chat tables are missing. Run the Stage 4 support migration first.'
            );
        }

        /*
         * Old blueprint compatibility:
         * current_agent_user_id -> assigned_user_id
         */
        if (
            Schema::hasColumn('WBO_Conversations', 'current_agent_user_id') &&
            Schema::hasColumn('WBO_Conversations', 'assigned_user_id')
        ) {
            DB::statement("
                UPDATE `WBO_Conversations`
                SET `assigned_user_id` = `current_agent_user_id`
                WHERE `assigned_user_id` IS NULL
                  AND `current_agent_user_id` IS NOT NULL
            ");
        }

        /*
         * Old blueprint primary key:
         * conversation_message_id -> message_id
         *
         * No other table references this primary key, so the rename preserves
         * the existing rows while matching the Stage 4 controller contract.
         */
        if (
            !Schema::hasColumn('WBO_ConversationMessages', 'message_id') &&
            Schema::hasColumn('WBO_ConversationMessages', 'conversation_message_id')
        ) {
            DB::statement("
                ALTER TABLE `WBO_ConversationMessages`
                CHANGE `conversation_message_id` `message_id`
                BIGINT NOT NULL AUTO_INCREMENT
            ");
        }

        if (!Schema::hasColumn('WBO_ConversationMessages', 'message_id')) {
            throw new \RuntimeException(
                'WBO_ConversationMessages.message_id is still missing after normalization.'
            );
        }

        /*
         * Preserve useful values from the old transfer design when the newer
         * Stage 4 columns are present.
         */
        if (
            Schema::hasColumn('WBO_ConversationTransfers', 'from_agent_user_id') &&
            Schema::hasColumn('WBO_ConversationTransfers', 'from_user_id')
        ) {
            DB::statement("
                UPDATE `WBO_ConversationTransfers`
                SET `from_user_id` = `from_agent_user_id`
                WHERE `from_user_id` IS NULL
                  AND `from_agent_user_id` IS NOT NULL
            ");
        }

        if (
            Schema::hasColumn('WBO_ConversationTransfers', 'to_agent_user_id') &&
            Schema::hasColumn('WBO_ConversationTransfers', 'to_user_id')
        ) {
            DB::statement("
                UPDATE `WBO_ConversationTransfers`
                SET `to_user_id` = `to_agent_user_id`
                WHERE `to_user_id` IS NULL
                  AND `to_agent_user_id` IS NOT NULL
            ");
        }

        if (
            Schema::hasColumn('WBO_ConversationTransfers', 'transfer_reason') &&
            Schema::hasColumn('WBO_ConversationTransfers', 'note')
        ) {
            DB::statement("
                UPDATE `WBO_ConversationTransfers`
                SET `note` = LEFT(`transfer_reason`, 255)
                WHERE (`note` IS NULL OR `note` = '')
                  AND `transfer_reason` IS NOT NULL
            ");
        }

        if (
            Schema::hasColumn('WBO_ConversationTransfers', 'transferred_at') &&
            Schema::hasColumn('WBO_ConversationTransfers', 'created_at')
        ) {
            DB::statement("
                UPDATE `WBO_ConversationTransfers`
                SET `created_at` = `transferred_at`
                WHERE `created_at` IS NULL
                  AND `transferred_at` IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // The old blueprint columns are left in place where harmless.
    }
};