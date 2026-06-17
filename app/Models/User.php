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

    /**
     * Kullanicinin ayar kaydini getirir, yoksa varsayilanla olusturur.
     */
    public function settings(): Setting
    {
        return $this->setting()->firstOrCreate(
            ['user_id' => $this->id],
            [
                'default_quote' => config('bot.default_quote', 'USDT'),
                'trading_mode' => config('bot.default_mode', 'simulation'),
                'recv_window' => config('bot.recv_window', 5000),
                'bot_enabled' => true,
            ]
        );
    }
}
