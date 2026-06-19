<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * trade_bots.strategy enum'una "grid_v2" (Grid v2 — sabit çapalı dip-alım merdiveni)
 * eklenir ve v2'nin çapasını (bot başladığı andaki fiyat) saklamak için
 * v2_anchor_price kolonu eklenir. Mevcut "grid" ve diğer stratejiler etkilenmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','grid_v2','rsi','ma_cross','macd','bollinger','smart_scalp') NOT NULL DEFAULT 'grid'");

        Schema::table('trade_bots', function (Blueprint $table) {
            // Grid v2 çapası: bot ilk çalıştığında o anki fiyata sabitlenir (yukarı kaymaz).
            $table->decimal('v2_anchor_price', 30, 12)->nullable()->after('order_size');
        });
    }

    public function down(): void
    {
        Schema::table('trade_bots', function (Blueprint $table) {
            $table->dropColumn('v2_anchor_price');
        });

        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','rsi','ma_cross','macd','bollinger','smart_scalp') NOT NULL DEFAULT 'grid'");
    }
};
