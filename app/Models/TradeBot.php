<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bir TRADE/SCALP stratejisi ornegi. Yatirim 'Coin'lerinden bagimsizdir.
 */
class TradeBot extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'tag',
        'base_asset',
        'quote_asset',
        'symbol',
        'symbol_type',
        'strategy',
        'enabled',
        'mode',
        'budget',
        'order_size',
        'v2_anchor_price',
        'max_buy_price',
        'params',
        'min_qty',
        'step_size',
        'min_notional',
        'base_precision',
        'quote_precision',
        'last_run_at',
        'last_signal',
        'last_synced_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'symbol_type' => 'integer',
            'enabled' => 'boolean',
            'budget' => 'float',
            'order_size' => 'float',
            'v2_anchor_price' => 'float',
            'max_buy_price' => 'float',
            'params' => 'array',
            'min_qty' => 'float',
            'step_size' => 'float',
            'min_notional' => 'float',
            'base_precision' => 'integer',
            'quote_precision' => 'integer',
            'last_run_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (TradeBot $bot) {
            $bot->position()->firstOrCreate([]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): HasOne
    {
        return $this->hasOne(TradePosition::class);
    }

    public function gridLevels(): HasMany
    {
        return $this->hasMany(TradeGridLevel::class)->orderBy('level_index');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(TradeOrder::class)->latest();
    }

    /** Stratejiye ozel parametre okur. */
    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->params, $key, $default);
    }

    public function effectiveMode(?string $globalMode = null): string
    {
        if ($this->mode === 'inherit') {
            return $globalMode ?: ($this->user?->settings()->trading_mode ?? 'simulation');
        }

        return $this->mode;
    }

    public function strategyLabel(): string
    {
        return match ($this->strategy) {
            'grid' => 'Grid',
            'grid_v2' => 'Grid v2 (Dip Merdiveni)',
            'rsi' => 'RSI',
            'ma_cross' => 'MA Kesişimi',
            'macd' => 'MACD',
            'bollinger' => 'Bollinger',
            'smart_scalp' => 'Akıllı Scalp',
            default => $this->strategy,
        };
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', true);
    }
}
