<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_positions', function (Blueprint $table) {
            // Trailing take-profit icin toplam K/Z zirvesi (gerceklesen + acik).
            $table->decimal('tp_peak', 30, 12)->nullable()->after('realized_profit');
        });
    }

    public function down(): void
    {
        Schema::table('trade_positions', function (Blueprint $table) {
            $table->dropColumn('tp_peak');
        });
    }
};
