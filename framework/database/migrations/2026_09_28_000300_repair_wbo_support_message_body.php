<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('WBO_ConversationMessages')) {
            throw new \RuntimeException(
                'WBO_ConversationMessages is missing. Run the Stage 4 support-chat migration first.'
            );
        }

        // Some older/local schemas used message_body instead of message.
        if (!Schema::hasColumn('WBO_ConversationMessages', 'message')) {
            Schema::table('WBO_ConversationMessages', function (Blueprint $table) {
                $table->text('message')->nullable();
            });
        }

        if (Schema::hasColumn('WBO_ConversationMessages', 'message_body')) {
            DB::statement("
                UPDATE `WBO_ConversationMessages`
                SET `message` = `message_body`
                WHERE (`message` IS NULL OR `message` = '')
                  AND `message_body` IS NOT NULL
            ");

            Schema::table('WBO_ConversationMessages', function (Blueprint $table) {
                $table->dropColumn('message_body');
            });
        }

        // Keep the active Stage 4 contract consistent.
        DB::table('WBO_ConversationMessages')
            ->whereNull('message')
            ->update(['message' => '']);

        DB::statement("
            ALTER TABLE `WBO_ConversationMessages`
            MODIFY `message` TEXT NOT NULL
        ");
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // We do not recreate the obsolete message_body column.
    }
};