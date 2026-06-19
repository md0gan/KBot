<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\Indicators;
use App\Services\Trade\TradeEngine;

/**
 * Price Action — mum formasyonu tabanlı, tek pozisyonlu strateji.
 *
 * Son KAPALI mum + bir önceki muma bakar (oluşmakta olan mumu değerlendirmez,
 * böylece sinyal mum kapanışıyla teyitlenir). Indicators::candlePattern ile:
 *  - BOĞA formasyonu (yutan boğa / çekiç) ve pozisyon yoksa → AL
 *  - AYI formasyonu (yutan ayı / kuyruklu yıldız) ve pozisyon varsa → SAT
 *  - Sabit kâr hedefi (tp_pct > 0) varsa ortalama maliyetin %X üstünde de SAT
 *
 * Merkezî Zarar Durdurma / Trailing Take-Profit / HTF trend filtresi (TradeEngine)
 * bu stratejide de geçerlidir. İndikatör stratejileriyle aynı desen.
 */
class PriceActionStrategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        $interval = (string) $bot->param('interval', '1h');
        $opts = [
            'engulfing' => (bool) $bot->param('pa_engulfing', true),
            'pin' => (bool) $bot->param('pa_pin', true),
            'wick_ratio' => (float) $bot->param('wick_ratio', 2.0),
            'min_body_pct' => (float) $bot->param('min_body_pct', 0.1),
        ];
        $tpPct = (float) $bot->param('tp_pct', 0);

        $o = $engine->client()->getOhlc($bot->symbol, $interval, 60, $bot->symbol_type ?? 1);
        $opens = $o['opens'] ?? [];
        $highs = $o['highs'] ?? [];
        $lows = $o['lows'] ?? [];
        $closes = $o['closes'] ?? [];
        $n = min(count($opens), count($highs), count($lows), count($closes));
        if ($n < 3) {
            return ['Price action: yetersiz mum verisi.'];
        }

        // Son KAPALI mum: sonuncu (n-1) oluşmakta kabul edilip atlanır.
        $ci = $n - 2;
        $pi = $n - 3;
        $cur = [$opens[$ci], $highs[$ci], $lows[$ci], $closes[$ci]];
        $prev = [$opens[$pi], $highs[$pi], $lows[$pi], $closes[$pi]];

        $sig = Indicators::candlePattern($prev, $cur, $opts);
        $bot->last_signal = 'PA '.($sig === 'bull' ? 'boğa' : ($sig === 'bear' ? 'ayı' : '—'));

        $pos = $bot->position;
        $holding = $pos && $pos->quantity > 0;

        if (! $holding) {
            if ($sig === 'bull') {
                if (! $engine->htfTrendOk($bot)) {
                    return ['Boğa formasyonu ama üst zaman dilimi trendi uygun değil → bekle'];
                }
                $order = $engine->buy($bot, $engine->effectiveOrderSize($bot), 'pa_buy', $price);

                return [$order
                    ? 'Boğa formasyonu → AL @ '.kb_price($price)
                    : 'Boğa formasyonu ama alım yapılamadı (limit/min?).'];
            }

            return ['Price action: alım sinyali yok (bekle).'];
        }

        // Pozisyon açık: ayı formasyonu veya sabit kâr hedefi → SAT
        if ($sig === 'bear') {
            $order = $engine->sell($bot, $pos->quantity, 'pa_sell', $price);

            return [$order ? 'Ayı formasyonu → SAT @ '.kb_price($price) : 'Ayı satış sinyali ama satılamadı.'];
        }

        if ($tpPct > 0 && (float) ($pos->avg_price ?? 0) > 0 && $price >= (float) $pos->avg_price * (1 + $tpPct / 100)) {
            $order = $engine->sell($bot, $pos->quantity, 'pa_tp', $price);

            return [$order ? 'Kâr hedefi (%'.kb_mult($tpPct).') → SAT @ '.kb_price($price) : 'Kâr hedefi satışı yapılamadı.'];
        }

        return ['Pozisyon açık: ayı formasyonu / kâr hedefi bekleniyor.'];
    }
}
