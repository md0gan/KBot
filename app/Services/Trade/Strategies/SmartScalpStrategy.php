<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * Akilli Scalp — cok-onayli ortalamaya donus.
 *
 * GIRIS (AL): RSI asiri satim ESIGINDE **ve** fiyat Bollinger ALT bandinda **ve**
 *   (ortak) ust zaman dilimi trendi yukari iken. Yani "trend yukari, gecici dip".
 * CIKIS (SAT): kucuk SABIT kar hedefi (scalp_tp_pct) **veya** RSI asiri alima donunce.
 *
 * Yuksek kazanma orani TASARIM geregidir (kucuk hedefler sik tutar) ama KAR GARANTISI
 * DEGILDIR — nadir buyuk zararlar icin **stop-loss (Zarar Durdurma)** ile kullanilmalidir.
 */
class SmartScalpStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '5m');
        $rsiPeriod = (int) $bot->param('rsi_period', 14);
        $oversold = (float) $bot->param('oversold', 30);
        $overbought = (float) $bot->param('overbought', 60);
        $bbPeriod = (int) $bot->param('bb_period', 20);
        $bbK = (float) $bot->param('bb_k', 2);
        $tpPct = (float) $bot->param('scalp_tp_pct', 0.6);

        $need = max($rsiPeriod, $bbPeriod) + 100;
        $closes = $engine->client()->getCloses($bot->symbol, $interval, $need, $bot->symbol_type ?? 1);
        $rsi = Indicators::rsi($closes, $rsiPeriod);
        $bands = Indicators::bollinger($closes, $bbPeriod, $bbK);
        if ($rsi === null || $bands === null) {
            return ['Akıllı Scalp: yetersiz veri.'];
        }

        $bot->last_signal = 'RSI '.round($rsi, 1).' · alt '.kb_price($bands['lower']);

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if (! $holding) {
            $dip = $rsi <= $oversold && $price <= $bands['lower'];
            if (! $dip) {
                return ['Akıllı Scalp: giriş koşulu yok (RSI '.round($rsi, 1).').'];
            }
            if (! $engine->htfTrendOk($bot)) {
                return ['Akıllı Scalp: dip var ama üst zaman dilimi trendi uygun değil → bekle.'];
            }
            $order = $engine->buy($bot, $engine->effectiveOrderSize($bot), 'scalp_buy', $price);

            return [$order ? 'Onaylı dip (RSI '.round($rsi, 1).' + alt bant) → AL' : 'Al sinyali ama alım yapılamadı (limit/min?).'];
        }

        // Cikis: kucuk sabit kar hedefi VEYA RSI asiri alim
        $avg = (float) ($pos->avg_price ?? 0);
        $tpHit = $avg > 0 && $price >= $avg * (1 + $tpPct / 100);
        $rsiExit = $rsi >= $overbought;

        if ($tpHit || $rsiExit) {
            $order = $engine->sell($bot, $pos->quantity, $tpHit ? 'scalp_tp' : 'scalp_rsi', $price);
            $why = $tpHit ? "kâr hedefi (+%{$tpPct})" : 'RSI '.round($rsi, 1)." ≥ {$overbought}";

            return [$order ? "Çıkış: {$why} → SAT" : 'Sat sinyali ama satılamadı.'];
        }

        return ['Akıllı Scalp: tutuluyor (kâr hedefi bekleniyor).'];
    }
}
