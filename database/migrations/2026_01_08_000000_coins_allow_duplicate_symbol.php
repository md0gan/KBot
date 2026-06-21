<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aynı sembolü (örn. BTC_TRY) farklı ayarlarla (farklı kar-al çarpanı vb.) birden
 * çok kez ekleyebilmek için coins tablosundaki (user_id, symbol) tekil kısıtını kaldırır.
 * Her coin kaydının kendi pozisyonu (positions.coin_id) zaten ayrıdır.
 * Kopyaları ayırt etmek için opsiyonel 'name' (etiket) kolonu eklenir.
 *
 * Not: (user_id, symbol) birleşik UNIQUE indeksi aynı zamanda user_id foreign key'ini
 * de besliyor. MySQL, FK'nın dayandığı indeksi doğrudan düşürmeye izin vermez
 * (error 1553). Bu yüzden ÖNCE user_id için ayrı bir index ekleriz (FK ona dayanır),
 * SONRA birleşik unique'i güvenle düşürürüz.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) FK'nın dayanabileceği yedek index (birleşik unique düşürülünce gerekli).
        Schema::table('coins', function (Blueprint $table) {
            $table->index('user_id', 'coins_user_id_index');
        });

        // 2) Artık birleşik unique güvenle düşürülebilir.
        Schema::table('coins', function (Blueprint $table) {
            $table->dropUnique('coins_user_id_symbol_unique');
        });

        // 3) Ayırt edici opsiyonel isim/etiket.
        if (! Schema::hasColumn('coins', 'name')) {
            Schema::table('coins', function (Blueprint $table) {
                $table->string('name', 60)->nullable()->after('symbol');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coins', 'name')) {
            Schema::table('coins', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }

        // Birleşik unique'i geri ekle (FK yeniden buna dayanabilir), sonra yedek index'i kaldır.
        Schema::table('coins', function (Blueprint $table) {
            $table->unique(['user_id', 'symbol']);
        });

        Schema::table('coins', function (Blueprint $table) {
            $table->dropIndex('coins_user_id_index');
        });
    }
};
