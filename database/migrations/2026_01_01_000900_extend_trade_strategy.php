<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trade_bots.strategy enum'una MACD ve Bollinger eklenir.
 * Ayri migration olarak yazildi ki trade tablolari onceden olusturulmus olsa
 * bile (enum 3 degerli) sorunsuz genisletilsin.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','rsi','ma_cross','macd','bollinger') NOT NULL DEFAULT 'grid'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trade_bots MODIFY strategy ENUM('grid','rsi','ma_cross') NOT NULL DEFAULT 'grid'");
    }
};
