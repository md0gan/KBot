<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trade_bots.strategy enum'una "price_action" (mum formasyonu tabanlı) eklenir.
 * Diğer stratejiler etkilenmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','grid_v2','rsi','ma_cross','macd','bollinger','smart_scalp','price_action') NOT NULL DEFAULT 'grid'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','grid_v2','rsi','ma_cross','macd','bollinger','smart_scalp') NOT NULL DEFAULT 'grid'");
    }
};
