<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradePosition extends Model
{
    protected $fillable = [
        'trade_bot_id',
        'quantity',
        'cost_basis',
        'avg_price',
        'realized_profit',
        'last_price',
        'last_value',
        'last_valued_at',
        'trades_count',
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
            'trades_count' => 'integer',
        ];
    }

    public function tradeBot(): BelongsTo
    {
        return $this->belongsTo(TradeBot::class);
    }

    public function hasHoldings(): bool
    {
        return $this->quantity > 0;
    }
}
