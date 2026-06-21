<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aynı sembolü (örn. BTC_TRY) farklı ayarlarla (farklı kar-al çarpanı vb.) birden
 * çok kez ekleyebilmek için coins tablosundaki (user_id, symbol) tekil kısıtını kaldırır.
 * Her coin kaydının kendi pozisyonu (positions.coin_id) zaten ayrıdır.
 * Kopyaları ayırt etmek için opsiyonel 'name' (etiket) kolonu eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coins', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'symbol']);
            $table->string('name', 60)->nullable()->after('symbol');
        });
    }

    public function down(): void
    {
        Schema::table('coins', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unique(['user_id', 'symbol']);
        });
    }
};
