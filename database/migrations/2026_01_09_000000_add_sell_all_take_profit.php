<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * coins.take_profit_strategy enum'una "sell_all" (tamamını sat, pozisyonu sıfırla)
 * seçeneğini ekler. Mevcut leave_capital / fixed_ratio etkilenmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE coins MODIFY take_profit_strategy ENUM('leave_capital','fixed_ratio','sell_all') NOT NULL DEFAULT 'leave_capital'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE coins MODIFY take_profit_strategy ENUM('leave_capital','fixed_ratio') NOT NULL DEFAULT 'leave_capital'");
    }
};
