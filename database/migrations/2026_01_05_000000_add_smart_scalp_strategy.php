<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trade_bots.strategy enum'una "smart_scalp" (Akilli Scalp) eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','rsi','ma_cross','macd','bollinger','smart_scalp') NOT NULL DEFAULT 'grid'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','rsi','ma_cross','macd','bollinger') NOT NULL DEFAULT 'grid'");
    }
};
