<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function assertValuesAreCompatible(string $table, string $column, array $allowed): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        $values = DB::table($table)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->all();

        $unexpected = array_values(array_diff($values, $allowed));

        if ($unexpected !== []) {
            throw new \RuntimeException(
                $table . '.' . $column .
                ' contains unexpected existing value(s): ' .
                implode(', ', $unexpected)
            );
        }
    }

    public function up(): void
    {
        $this->assertValuesAreCompatible(
            'WBO_Conversations',
            'status',
            ['BOT', 'WAITING_STAFF', 'ACTIVE', 'CLOSED']
        );

        $this->assertValuesAreCompatible(
            'WBO_ConversationMessages',
            'sender_type',
            ['CUSTOMER', 'BOT', 'STAFF', 'SYSTEM']
        );

        $this->assertValuesAreCompatible(
            'WBO_ConversationTransfers',
            'transfer_type',
            ['ESCALATED', 'ASSIGNED', 'REASSIGNED', 'CLOSED']
        );

        if (
            Schema::hasTable('WBO_Conversations') &&
            Schema::hasColumn('WBO_Conversations', 'status')
        ) {
            DB::statement("
                ALTER TABLE `WBO_Conversations`
                MODIFY `status`
                ENUM('BOT','WAITING_STAFF','ACTIVE','CLOSED')
                NOT NULL DEFAULT 'BOT'
            ");
        }

        if (
            Schema::hasTable('WBO_ConversationMessages') &&
            Schema::hasColumn('WBO_ConversationMessages', 'sender_type')
        ) {
            DB::statement("
                ALTER TABLE `WBO_ConversationMessages`
                MODIFY `sender_type`
                ENUM('CUSTOMER','BOT','STAFF','SYSTEM')
                NOT NULL
            ");
        }

        if (
            Schema::hasTable('WBO_ConversationTransfers') &&
            Schema::hasColumn('WBO_ConversationTransfers', 'transfer_type')
        ) {
            DB::statement("
                ALTER TABLE `WBO_ConversationTransfers`
                MODIFY `transfer_type`
                ENUM('ESCALATED','ASSIGNED','REASSIGNED','CLOSED')
                NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};