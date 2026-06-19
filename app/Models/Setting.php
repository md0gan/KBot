<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'user_id',
        'api_key',
        'api_secret',
        'base_url',
        'market_base_url',
        'recv_window',
        'default_quote',
        'trading_mode',
        'bot_enabled',
        'api_verified_at',
        'api_status',
        'telegram_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
        'telegram_connect_token',
        'telegram_connected_at',
        'tg_notify_trades',
        'tg_notify_errors',
        'tg_notify_balance',
        'low_balance_threshold',
        'last_quote_balance',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
        'telegram_bot_token',
    ];

    protected function casts(): array
    {
        return [
            // API anahtarlari veritabaninda sifreli saklanir (APP_KEY ile).
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'telegram_bot_token' => 'encrypted',
            'recv_window' => 'integer',
            'bot_enabled' => 'boolean',
            'telegram_enabled' => 'boolean',
            'tg_notify_trades' => 'boolean',
            'tg_notify_errors' => 'boolean',
            'tg_notify_balance' => 'boolean',
            'low_balance_threshold' => 'float',
            'last_quote_balance' => 'float',
            'api_verified_at' => 'datetime',
            'telegram_connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function effectiveBaseUrl(): string
    {
        return $this->base_url ?: config('bot.base_url');
    }

    public function effectiveMarketBaseUrl(): string
    {
        return $this->market_base_url ?: config('bot.market_base_url');
    }

    public function hasApiCredentials(): bool
    {
        return filled($this->api_key) && filled($this->api_secret);
    }

    /**
     * Bildirim gonderiminde kullanilacak token: kullanici kendi botunu girdiyse
     * onu, aksi halde uygulama geneli ortak bot token'ini kullanir.
     */
    public function effectiveTelegramToken(): ?string
    {
        if (filled($this->telegram_bot_token)) {
            return $this->telegram_bot_token;
        }

        return AppSetting::get('telegram_app_bot_token');
    }

    public function hasTelegram(): bool
    {
        return (bool) $this->telegram_enabled
            && filled($this->effectiveTelegramToken())
            && filled($this->telegram_chat_id);
    }

    /** Telegram sohbeti bagli mi (chat_id yakalanmis ve bekleyen kod yok). */
    public function isTelegramConnected(): bool
    {
        return filled($this->telegram_chat_id) && blank($this->telegram_connect_token);
    }
}
