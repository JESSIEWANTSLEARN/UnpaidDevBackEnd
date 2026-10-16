<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('WBO_Products', 'reorder_point')) {
            Schema::table('WBO_Products', function (Blueprint $table) {
                $table
                    ->unsignedInteger('reorder_point')
                    ->default(10)
                    ->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('WBO_Products', 'reorder_point')) {
            Schema::table('WBO_Products', function (Blueprint $table) {
                $table->dropColumn('reorder_point');
            });
        }
    }
};