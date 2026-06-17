<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coin_id')->unique()->constrained()->cascadeOnDelete();

            // Botun tuttugu miktar (base asset)
            $table->decimal('quantity', 30, 12)->default(0);
            // Sermaye: bu miktar icin harcanan ve hala "yatirimda" olan kote tutar
            $table->decimal('cost_basis', 30, 12)->default(0);
            // Ortalama maliyet = cost_basis / quantity
            $table->decimal('avg_price', 30, 12)->default(0);
            // USDT'ye cevrilmis (gerceklesmis) toplam kar
            $table->decimal('realized_profit', 30, 12)->default(0);

            // Son degerleme
            $table->decimal('last_price', 30, 12)->nullable();
            $table->decimal('last_value', 30, 12)->nullable();
            $table->timestamp('last_valued_at')->nullable();

            // Kac kez kar-al tetiklendi
            $table->unsignedInteger('profit_takes_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
