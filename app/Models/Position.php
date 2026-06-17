<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    protected $fillable = [
        'coin_id',
        'quantity',
        'cost_basis',
        'avg_price',
        'realized_profit',
        'last_price',
        'last_value',
        'last_valued_at',
        'profit_takes_count',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'cost_basis' => 'float',
            'avg_price' => 'float',
            'realized_profit' => 'float',
            'last_price' => 'float',
            'last_value' => 'float',
            'last_valued_at' => 'datetime',
            'profit_takes_count' => 'integer',
        ];
    }

    public function coin(): BelongsTo
    {
        return $this->belongsTo(Coin::class);
    }

    /** Verilen fiyata gore guncel piyasa degeri (kote). */
    public function valueAt(float $price): float
    {
        return $this->quantity * $price;
    }

    /** Verilen fiyata gore kar/zarar orani (deger / sermaye). */
    public function profitRatioAt(float $price): float
    {
        if ($this->cost_basis <= 0) {
            return 0.0;
        }

        return ($this->quantity * $price) / $this->cost_basis;
    }

    /** Verilen fiyata gore gerceklesmemis kar/zarar (kote). */
    public function unrealizedAt(float $price): float
    {
        return ($this->quantity * $price) - $this->cost_basis;
    }

    public function hasHoldings(): bool
    {
        return $this->quantity > 0 && $this->cost_basis > 0;
    }
}
