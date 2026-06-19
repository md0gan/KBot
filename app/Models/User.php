<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class);
    }

    /**
     * Yonetici mi? Kurulumda olusturulan ilk kullanici (id=1) yoneticidir;
     * uygulama geneli ortak Telegram botunu yalnizca yonetici ayarlayabilir.
     */
    public function isAdmin(): bool
    {
        return (int) $this->id === 1;
    }

    public function coins(): HasMany
    {
        return $this->hasMany(Coin::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BotLog::class);
    }

    public function tradeBots(): HasMany
    {
        return $this->hasMany(TradeBot::class);
    }

    public function tradeOrders(): HasMany
    {
        return $this->hasMany(TradeOrder::class);
    }

    /**
     * Kullanicinin ayar kaydini getirir, yoksa varsayilanla olusturur.
     */
    public function settings(): Setting
    {
        return $this->setting()->firstOrCreate(
            ['user_id' => $this->id],
            [
                'default_quote' => config('bot.default_quote', 'TRY'),
                'trading_mode' => config('bot.default_mode', 'simulation'),
                'recv_window' => config('bot.recv_window', 5000),
                'bot_enabled' => true,
            ]
        );
    }
}
