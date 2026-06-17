<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Coin extends Model
{
    protected $fillable = [
        'user_id',
        'base_asset',
        'quote_asset',
        'symbol',
        'symbol_type',
        'enabled',
        'mode',
        'buy_amount',
        'interval',
        'interval_dow',
        'interval_dom',
        'buy_hour',
        'profit_multiplier',
        'take_profit_strategy',
        'sell_ratio',
        'max_buy_price',
        'min_qty',
        'step_size',
        'min_notional',
        'base_precision',
        'quote_precision',
        'last_buy_at',
        'next_buy_at',
        'last_synced_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'symbol_type' => 'integer',
            'enabled' => 'boolean',
            'buy_amount' => 'float',
            'buy_hour' => 'integer',
            'interval_dow' => 'integer',
            'interval_dom' => 'integer',
            'profit_multiplier' => 'float',
            'sell_ratio' => 'float',
            'max_buy_price' => 'float',
            'min_qty' => 'float',
            'step_size' => 'float',
            'min_notional' => 'float',
            'base_precision' => 'integer',
            'quote_precision' => 'integer',
            'last_buy_at' => 'datetime',
            'next_buy_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Yeni coin'e otomatik pozisyon kaydi olustur
        static::created(function (Coin $coin) {
            $coin->position()->firstOrCreate([]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): HasOne
    {
        return $this->hasOne(Position::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class)->latest();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BotLog::class)->latest();
    }

    /* ----------------------------------------------------------------------
     | Yardimcilar
     * -------------------------------------------------------------------- */

    /**
     * Bu coin icin gecerli islem modu. 'inherit' ise kullanicinin genel modu.
     */
    public function effectiveMode(?string $globalMode = null): string
    {
        if ($this->mode === 'inherit') {
            return $globalMode ?: ($this->user?->settings()->trading_mode ?? 'simulation');
        }

        return $this->mode;
    }

    public function isLive(?string $globalMode = null): bool
    {
        return $this->effectiveMode($globalMode) === 'live';
    }

    /**
     * Son alimdan sonra bir sonraki alim zamanini hesaplar.
     */
    public function nextBuyAfter(Carbon $from): Carbon
    {
        // Saat hizalamasi yok: son alimdan itibaren tam bir periyot ekle.
        return match ($this->interval) {
            'hourly' => $from->copy()->addHour(),
            'daily' => $from->copy()->addDay(),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            default => $from->copy()->addWeek(), // weekly
        };
    }

    /**
     * Alim vakti geldi mi?
     */
    public function isDue(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->enabled && $this->next_buy_at !== null && $this->next_buy_at->lte($now);
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', true);
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('enabled', true)
            ->whereNotNull('next_buy_at')
            ->where('next_buy_at', '<=', now());
    }
}
