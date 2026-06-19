<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * Hareketli ortalama kesisimi: kisa MA uzun MA'yi yukari keserse AL,
 * asagi keserse tamamini SAT. Tip sma/ema.
 */
class MaCrossStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '15m');
        $short = (int) $bot->param('short', 9);
        $long = (int) $bot->param('long', 21);
        $type = (string) $bot->param('ma_type', 'ema');

        if ($short >= $long) {
            return ['MA: kisa periyot uzun periyottan kucuk olmali.'];
        }

        $closes = $engine->client()->getCloses($bot->symbol, $interval, $long + 80, $bot->symbol_type ?? 1);
        $signal = Indicators::crossSignal($closes, $short, $long, $type);

        $bot->last_signal = $signal
            ? strtoupper($type)." {$short}/{$long}: {$signal}"
            : strtoupper($type)." {$short}/{$long}: kesisim yok";

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if ($signal === 'bullish' && ! $holding) {
            $order = $engine->buy($bot, $engine->effectiveOrderSize($bot), 'ma_buy', $price);

            return [$order ? 'MA yukari kesti → AL' : 'MA al sinyali ama alim yapilamadi.'];
        }

        if ($signal === 'bearish' && $holding) {
            $order = $engine->sell($bot, $pos->quantity, 'ma_sell', $price);

            return [$order ? 'MA asagi kesti → SAT' : 'MA sat sinyali ama satilamadi.'];
        }

        return [$signal ? "MA {$signal}: pozisyon uygun degil" : 'MA: kesisim yok'];
    }
}
