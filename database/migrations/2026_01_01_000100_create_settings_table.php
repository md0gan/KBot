<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Binance TR API kimlik bilgileri (sifreli saklanir - model cast'i ile)
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();

            // API uc noktalari (bos ise config/bot.php varsayilani kullanilir)
            $table->string('base_url')->nullable();
            $table->string('market_base_url')->nullable();
            $table->unsignedInteger('recv_window')->default(5000);

            // Genel ayarlar
            $table->string('default_quote', 16)->default('TRY');
            $table->enum('trading_mode', ['simulation', 'live'])->default('simulation');
            $table->boolean('bot_enabled')->default(true);

            // Son baglanti testi bilgisi
            $table->timestamp('api_verified_at')->nullable();
            $table->string('api_status')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
