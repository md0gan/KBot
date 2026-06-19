<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\TradeEngine;

/**
 * Grid (market emir tabanli): aralik kademelere bolunur. Fiyat bir kademenin
 * ALIS seviyesine inince o kademe icin alim; SATIS seviyesine (bir kademe yukari)
 * cikinca satim yapilir. Her kademe kendi durumunu tutar.
 */
class GridStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $engine->ensureGridLevels($bot);

        $levels = $bot->gridLevels()->get();
        if ($levels->isEmpty()) {
            return ['Grid kademeleri kurulamadi (aralik gecersiz olabilir).'];
        }

        $perLevelQuote = $bot->budget > 0
            ? $bot->budget / $levels->count()
            : $bot->order_size;

        $lines = [];

        foreach ($levels as $level) {
            if ($level->status === 'waiting_buy' && $price <= $level->buy_price) {
                $order = $engine->buy($bot, $perLevelQuote, "grid_buy_L{$level->level_index}", $price);
                if ($order && $order->quantity > 0) {
                    $level->status = 'holding';
                    $level->quantity = $order->quantity;
                    $level->buy_order_quote = $order->quote_amount;
                    $level->save();
                    $lines[] = "Grid AL L{$level->level_index} @ ".kb_price($price);
                }
            } elseif ($level->status === 'holding' && $price >= $level->sell_price) {
                $order = $engine->sell($bot, $level->quantity, "grid_sell_L{$level->level_index}", $price);
                if ($order && $order->quantity > 0) {
                    $level->status = 'waiting_buy';
                    $level->quantity = 0;
                    $level->buy_order_quote = 0;
                    $level->save();
                    $lines[] = "Grid SAT L{$level->level_index} @ ".kb_price($price);
                }
            }
        }

        return $lines ?: ['Grid: işlem yok (fiyat kademeler arasında).'];
    }
}
