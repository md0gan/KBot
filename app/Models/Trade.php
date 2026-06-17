<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    protected $fillable = [
        'user_id',
        'coin_id',
        'symbol',
        'side',
        'kind',
        'mode',
        'quantity',
        'price',
        'quote_amount',
        'fee',
        'fee_asset',
        'realized_profit',
        'order_id',
        'client_id',
        'status',
        'reason',
        'error',
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

    public function coin(): BelongsTo
    {
        return $this->belongsTo(Coin::class);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'dca_buy' => 'Düzenli Alım',
            'manual_buy' => 'Manuel Alım',
            'profit_take' => 'Kar-Al',
            'manual_sell' => 'Manuel Satış',
            default => $this->kind,
        };
    }
}
