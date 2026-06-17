<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coins', function (Blueprint $table) {
            // Fiyat filtresi: guncel fiyat bunun UZERINDEYSE zamanlanmis alim yapilmaz.
            // Bos ise filtre yok. (Manuel "Al" bu filtreyi yok sayar.)
            $table->decimal('max_buy_price', 30, 12)->nullable()->after('sell_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('coins', function (Blueprint $table) {
            $table->dropColumn('max_buy_price');
        });
    }
};
