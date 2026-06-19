<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uygulama geneli ayarlar (ortak Telegram botu token'i, bot kullanici adi,
        // getUpdates offset imleci vb.). Anahtar/deger; secret degerler sifrelenir.
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // Her kullanicinin kendi Telegram'ina "tek tikla bagla" akisi icin alanlar.
        Schema::table('settings', function (Blueprint $table) {
            // Bagla butonuna basinca uretilen tek kullanimlik kod (deep-link ?start=).
            $table->string('telegram_connect_token')->nullable()->after('telegram_chat_id');
            // Chat baglandiginda doldurulur.
            $table->timestamp('telegram_connected_at')->nullable()->after('telegram_connect_token');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['telegram_connect_token', 'telegram_connected_at']);
        });

        Schema::dropIfExists('app_settings');
    }
};
