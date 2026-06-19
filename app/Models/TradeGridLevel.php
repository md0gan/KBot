<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeGridLevel extends Model
{
    protected $fillable = [
        'trade_bot_id',
        'level_index',
        'buy_price',
        'sell_price',
        'status',
        'quantity',
        'buy_order_quote',
    ];

    protected function casts(): array
    {
        return [
            'level_index' => 'integer',
            'buy_price' => 'float',
            'sell_price' => 'float',
            'quantity' => 'float',
            'buy_order_quote' => 'float',
        ];
    }

    public function tradeBot(): BelongsTo
    {
        return $this->belongsTo(TradeBot::class);
    }

    public function isHolding(): bool
    {
        return $this->status === 'holding';
    }
}
