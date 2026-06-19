<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * Bollinger: fiyat alt banda deger/altina inerse AL, ust banda deger/ustune
 * cikarsa SAT. Tek pozisyon.
 */
class BollingerStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '15m');
        $period = (int) $bot->param('period', 20);
        $k = (float) $bot->param('k', 2);

        $closes = $engine->client()->getCloses($bot->symbol, $interval, $period + 100, $bot->symbol_type ?? 1);
        $bands = Indicators::bollinger($closes, $period, $k);
        if ($bands === null) {
            return ['Bollinger: yetersiz veri.'];
        }

        $bot->last_signal = 'BB '.kb_price($bands['lower']).' / '.kb_price($bands['upper']);

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if ($price <= $bands['lower'] && ! $holding) {
            if (! $this->passesEntryFilters($bot, $closes, $price)) {
                return ['BB al sinyali var ama filtre (trend/RSI) engelledi.'];
            }
            $order = $engine->buy($bot, $bot->order_size, 'bb_buy', $price);

            return [$order ? 'Fiyat alt bantta → AL' : 'BB al sinyali ama alim yapilamadi.'];
        }

        if ($price >= $bands['upper'] && $holding) {
            $order = $engine->sell($bot, $pos->quantity, 'bb_sell', $price);

            return [$order ? 'Fiyat üst bantta → SAT' : 'BB sat sinyali ama satilamadi.'];
        }

        return ['Bollinger: bant içinde, bekle'];
    }

    protected function passesEntryFilters(TradeBot $bot, array $closes, float $price): bool
    {
        $trendMa = (int) $bot->param('trend_ma', 0);
        if ($trendMa > 1) {
            $ema = Indicators::emaSeries($closes, $trendMa);
            $last = null;
            for ($i = count($ema) - 1; $i >= 0; $i--) {
                if ($ema[$i] !== null) {
                    $last = (float) $ema[$i];
                    break;
                }
            }
            if ($last !== null && $price < $last) {
                return false;
            }
        }

        if ($bot->param('confirm_rsi', false)) {
            $rsi = Indicators::rsi($closes, 14);
            if ($rsi !== null && $rsi > 40) {
                return false;
            }
        }

        return true;
    }
}
