<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false)->after('api_status');
            $table->text('telegram_bot_token')->nullable()->after('telegram_enabled');   // sifreli
            $table->string('telegram_chat_id')->nullable()->after('telegram_bot_token');

            // Hangi olaylarda bildirim gonderilsin
            $table->boolean('tg_notify_trades')->default(true)->after('telegram_chat_id');
            $table->boolean('tg_notify_errors')->default(true)->after('tg_notify_trades');
            $table->boolean('tg_notify_balance')->default(true)->after('tg_notify_errors');

            // Bakiye takibi (canli mod)
            $table->decimal('low_balance_threshold', 30, 12)->nullable()->after('tg_notify_balance');
            $table->decimal('last_quote_balance', 30, 12)->nullable()->after('low_balance_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_enabled',
                'telegram_bot_token',
                'telegram_chat_id',
                'tg_notify_trades',
                'tg_notify_errors',
                'tg_notify_balance',
                'low_balance_threshold',
                'last_quote_balance',
            ]);
        });
    }
};
