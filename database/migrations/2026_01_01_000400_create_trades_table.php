<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coin_id')->constrained()->cascadeOnDelete();

            $table->string('symbol', 48);
            $table->enum('side', ['BUY', 'SELL']);
            // Islemi tetikleyen sebep
            $table->enum('kind', ['dca_buy', 'manual_buy', 'profit_take', 'manual_sell'])->default('dca_buy');
            $table->enum('mode', ['simulation', 'live'])->default('simulation');

            $table->decimal('quantity', 30, 12)->default(0);     // base
            $table->decimal('price', 30, 12)->default(0);        // birim fiyat
            $table->decimal('quote_amount', 30, 12)->default(0); // harcanan/elde edilen kote tutar
            $table->decimal('fee', 30, 12)->default(0);
            $table->string('fee_asset', 16)->nullable();

            // Pozisyona etkisi (kar-al sonrasi gerceklesmis kar)
            $table->decimal('realized_profit', 30, 12)->default(0);

            $table->string('order_id')->nullable();
            $table->string('client_id')->nullable();
            $table->string('status')->nullable();   // NEW/FILLED/... ya da SIMULATED
            $table->text('reason')->nullable();
            $table->text('error')->nullable();
            $table->json('raw')->nullable();

            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['coin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
