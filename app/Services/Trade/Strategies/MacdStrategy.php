<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * MACD: macd cizgisi signal cizgisini yukari keserse AL, asagi keserse SAT.
 */
class MacdStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '15m');
        $fast = (int) $bot->param('fast', 12);
        $slow = (int) $bot->param('slow', 26);
        $signal = (int) $bot->param('signal', 9);

        if ($fast >= $slow) {
            return ['MACD: hizli periyot yavastan kucuk olmali.'];
        }

        $closes = $engine->client()->getCloses($bot->symbol, $interval, $slow + $signal + 100, $bot->symbol_type ?? 1);
        $cross = Indicators::macdCross($closes, $fast, $slow, $signal);

        $bot->last_signal = 'MACD: '.($cross ?? 'kesisim yok');

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if ($cross === 'bullish' && ! $holding) {
            $order = $engine->buy($bot, $bot->order_size, 'macd_buy', $price);

            return [$order ? 'MACD yukari kesti → AL' : 'MACD al sinyali ama alim yapilamadi.'];
        }

        if ($cross === 'bearish' && $holding) {
            $order = $engine->sell($bot, $pos->quantity, 'macd_sell', $price);

            return [$order ? 'MACD asagi kesti → SAT' : 'MACD sat sinyali ama satilamadi.'];
        }

        return [$cross ? "MACD {$cross}: pozisyon uygun degil" : 'MACD: kesisim yok'];
    }
}
