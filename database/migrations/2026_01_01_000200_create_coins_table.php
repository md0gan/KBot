<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sembol tanimi
            $table->string('base_asset', 32);              // BTC
            $table->string('quote_asset', 16)->default('TRY'); // TRY (Binance TR)
            $table->string('symbol', 48);                  // orn. BTC_TRY
            $table->unsignedTinyInteger('symbol_type')->default(1); // 1=MAIN, 2=NEXT

            // Durum / mod
            $table->boolean('enabled')->default(true);
            $table->enum('mode', ['inherit', 'simulation', 'live'])->default('inherit');

            // DCA (duzenli alim) parametreleri
            $table->decimal('buy_amount', 30, 12)->default(10);  // her periyotta kote miktar
            $table->enum('interval', ['hourly', 'daily', 'weekly', 'monthly'])->default('weekly');
            $table->unsignedTinyInteger('interval_dow')->nullable(); // haftalik: 0=Pazar..6=Cumartesi
            $table->unsignedTinyInteger('interval_dom')->nullable(); // aylik: 1..28
            $table->unsignedTinyInteger('buy_hour')->default(9);     // alim saati (0-23)

            // Kar-al parametreleri
            $table->decimal('profit_multiplier', 12, 4)->default(2);  // deger >= carpan x sermaye
            $table->enum('take_profit_strategy', ['leave_capital', 'fixed_ratio'])->default('leave_capital');
            $table->decimal('sell_ratio', 8, 4)->default(0.5);        // fixed_ratio: satilacak deger orani

            // Sembol filtreleri (senkron ile doldurulur)
            $table->decimal('min_qty', 30, 12)->nullable();
            $table->decimal('step_size', 30, 12)->nullable();
            $table->decimal('min_notional', 30, 12)->nullable();
            $table->unsignedTinyInteger('base_precision')->nullable();
            $table->unsignedTinyInteger('quote_precision')->nullable();

            // Zamanlama / takip
            $table->timestamp('last_buy_at')->nullable();
            $table->timestamp('next_buy_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'symbol']);
            $table->index(['enabled', 'next_buy_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coins');
    }
};
