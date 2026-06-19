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
            'price_action' => 'Price Action (Mum)',
            default => $this->strategy,
        };
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', true);
    }

    /**
     * Grid v2: güncel fiyatın hemen ALTINDAKİ, tutulmayan ilk alım seviyesi ("sonraki dip").
     * Çapa + adımdan hesaplanır; ilgili seviye satırı henüz oluşmamış olsa bile çalışır.
     * Tutulan (holding) seviyeler atlanır. Hesaplanamazsa null döner.
     */
    public function gridV2NextDip(float $price): ?float
    {
        $anchor = (float) ($this->v2_anchor_price ?? 0);
        $step = (float) $this->param('v2_step_pct', 1) / 100;
        if ($anchor <= 0 || $price <= 0 || $step <= 0) {
            return null;
        }

        $kFloor = (int) ceil(1 / $step) - 1; // buy_price > 0 için k < 1/step
        $heldSet = array_flip(
            $this->gridLevels->where('status', 'holding')->pluck('level_index')->all()
        );

        // Fiyatın hemen altındaki ilk seviye indeksinden başla.
        $k = (int) floor((1 - $price / $anchor) / $step + 1e-9) + 1;
        if ($k < 1) {
            $k = 1;
        }
        for (; $k <= $kFloor; $k++) {
            $buy = $anchor * (1 - $k * $step);
            if ($buy <= 0) {
                break;
            }
            if ($buy < $price && ! isset($heldSet[$k])) {
                return $buy;
            }
        }

        return null;
    }
}
