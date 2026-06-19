<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeOrder extends Model
{
    protected $fillable = [
        'user_id',
        'trade_bot_id',
        'symbol',
        'side',
        'strategy',
        'mode',
        'quantity',
        'price',
        'quote_amount',
        'fee',
        'realized_profit',
        'reason',
        'status',
        'order_id',
        'raw',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'quote_amount' => 'float',
            'fee' => 'float',
            'realized_profit' => 'float',
            'raw' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeBot(): BelongsTo
    {
        return $this->belongsTo(TradeBot::class);
    }
}
