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
            'recv_window' => 'integer',
            'bot_enabled' => 'boolean',
            'api_verified_at' => 'datetime',
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
}
