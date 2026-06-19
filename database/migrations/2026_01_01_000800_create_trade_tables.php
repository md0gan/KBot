<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRADE / SCALP modu tablolari. Yatirim tarafindan (coins/positions/trades)
 * tamamen BAGIMSIZDIR; ayri izlenir, birbirine karismaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bir trade stratejisi ornegi (grid / rsi / ma_cross)
        Schema::create('trade_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('base_asset', 32);
            $table->string('quote_asset', 16)->default('TRY');
            $table->string('symbol', 48);
            $table->unsignedTinyInteger('symbol_type')->default(1);

            $table->enum('strategy', ['grid', 'rsi', 'ma_cross'])->default('grid');
            $table->boolean('enabled')->default(true);
            $table->enum('mode', ['inherit', 'simulation', 'live'])->default('inherit');

            $table->decimal('budget', 30, 12)->default(0);      // toplam ayrilan kote
            $table->decimal('order_size', 30, 12)->default(0);  // islem basina kote (rsi/ma)
            $table->decimal('max_buy_price', 30, 12)->nullable();

            $table->json('params')->nullable();                 // stratejiye ozel ayarlar

            // Sembol filtreleri (ayri senkron)
            $table->decimal('min_qty', 30, 12)->nullable();
            $table->decimal('step_size', 30, 12)->nullable();
            $table->decimal('min_notional', 30, 12)->nullable();
            $table->unsignedTinyInteger('base_precision')->nullable();
            $table->unsignedTinyInteger('quote_precision')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_signal')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->index(['enabled', 'user_id']);
        });

        // Trade botunun guncel pozisyonu (1:1) - yatirim pozisyonundan AYRI
        Schema::create('trade_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_bot_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 30, 12)->default(0);
            $table->decimal('cost_basis', 30, 12)->default(0);
            $table->decimal('avg_price', 30, 12)->default(0);
            $table->decimal('realized_profit', 30, 12)->default(0);
            $table->decimal('last_price', 30, 12)->nullable();
            $table->decimal('last_value', 30, 12)->nullable();
            $table->timestamp('last_valued_at')->nullable();
            $table->unsignedInteger('trades_count')->default(0);
            $table->timestamps();
        });

        // Grid stratejisi kademe durumlari
        Schema::create('trade_grid_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_bot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level_index');
            $table->decimal('buy_price', 30, 12);
            $table->decimal('sell_price', 30, 12);
            $table->enum('status', ['waiting_buy', 'holding'])->default('waiting_buy');
            $table->decimal('quantity', 30, 12)->default(0);     // tutulan base
            $table->decimal('buy_order_quote', 30, 12)->default(0); // harcanan kote
            $table->timestamps();
            $table->index('trade_bot_id');
        });

        // Trade islem defteri (yatirim 'trades' tablosundan AYRI)
        Schema::create('trade_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_bot_id')->constrained()->cascadeOnDelete();

            $table->string('symbol', 48);
            $table->enum('side', ['BUY', 'SELL']);
            $table->string('strategy', 16)->default('grid');
            $table->enum('mode', ['simulation', 'live'])->default('simulation');

            $table->decimal('quantity', 30, 12)->default(0);
            $table->decimal('price', 30, 12)->default(0);
            $table->decimal('quote_amount', 30, 12)->default(0);
            $table->decimal('fee', 30, 12)->default(0);
            $table->decimal('realized_profit', 30, 12)->default(0);

            $table->string('reason')->nullable();
            $table->string('status')->nullable();
            $table->string('order_id')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['trade_bot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_orders');
        Schema::dropIfExists('trade_grid_levels');
        Schema::dropIfExists('trade_positions');
        Schema::dropIfExists('trade_bots');
    }
};
