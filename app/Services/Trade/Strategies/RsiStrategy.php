<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * RSI: RSI <= alt esik ve pozisyon yoksa AL; RSI >= ust esik ve pozisyon varsa
 * tamamini SAT. Tek pozisyon mantigi.
 */
class RsiStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '15m');
        $period = (int) $bot->param('period', 14);
        $oversold = (float) $bot->param('oversold', 30);
        $overbought = (float) $bot->param('overbought', 70);

        $closes = $engine->client()->getCloses($bot->symbol, $interval, $period + 80, $bot->symbol_type ?? 1);
        $rsi = Indicators::rsi($closes, $period);
        if ($rsi === null) {
            return ['RSI: yetersiz veri.'];
        }

        $bot->last_signal = 'RSI '.round($rsi, 1);

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if ($rsi <= $oversold && ! $holding) {
            if (! $engine->htfTrendOk($bot)) {
                return ['RSI '.round($rsi, 1)." ≤ {$oversold} ama üst zaman dilimi trendi uygun değil → bekle"];
            }
            $order = $engine->buy($bot, $engine->effectiveOrderSize($bot), 'rsi_buy', $price);

            return [$order
                ? 'RSI '.round($rsi, 1)." ≤ {$oversold} → AL"
                : 'RSI al sinyali ama alim yapilamadi (limit/min?).'];
        }

        if ($rsi >= $overbought && $holding) {
            $order = $engine->sell($bot, $pos->quantity, 'rsi_sell', $price);

            return [$order
                ? 'RSI '.round($rsi, 1)." ≥ {$overbought} → SAT"
                : 'RSI sat sinyali ama satilamadi.'];
        }

        return ['RSI '.round($rsi, 1).': bekle'];
    }
}
