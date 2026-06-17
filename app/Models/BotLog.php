<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLog extends Model
{
    protected $fillable = [
        'user_id',
        'coin_id',
        'level',
        'event',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
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

    public static function write(string $level, string $event, string $message, array $context = [], ?int $userId = null, ?int $coinId = null): void
    {
        static::create([
            'user_id' => $userId,
            'coin_id' => $coinId,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context ?: null,
        ]);
    }
}
