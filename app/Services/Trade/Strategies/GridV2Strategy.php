<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Models\TradeGridLevel;
use App\Services\Trade\TradeEngine;

/**
 * Grid v2 — sabit çapalı dip-alım merdiveni.
 *
 * Mevcut "grid" stratejisinden farkı: önceden tanımlı alt/üst aralık ve sabit kademe
 * sayısı YOKTUR. Tek parametre bir düşüş adımıdır (v2_step_pct, "%x"). Botun ilk
 * çalıştığı andaki fiyat ÇAPA olarak sabitlenir (yukarı kaymaz) ve alım seviyeleri
 * bu çapanın altına DOĞRUSAL yerleşir:
 *
 *     buy_price(k) = çapa × (1 − k·x)      (k = 1, 2, 3, …)
 *
 * Fiyat, henüz "dolu" olmayan bir seviyenin alış fiyatına yukarıdan aşağı dokununca
 * (kesişim) o seviyeden bir alım yapılır; seviye "dolu" olur ve lot satılana kadar
 * tekrar alım yapmaz. Lot satılınca seviye yeniden "boş"a döner; fiyat o seviyeye
 * tekrar inerse yeniden alır.
 *
 * Seviyeler sabit MUTLAK fiyata çakılı olduğundan (hareketli bir tepeye göre değil),
 * fiyatın %x yükselip tekrar %x düşmesi hiçbir seviyeye dokunmaz → boş yere ALIM YAPMAZ.
 * Alım yalnızca fiyat gerçekten yeni bir dibe, boş bir seviyeye inince olur.
 *
 * Satış hedefi (her lot için):
 *   - sell_profit_pct > 0  → alış × (1 + sell_profit_pct/100)
 *   - sell_profit_pct = 0  → alış × (1 + x)  (bir adım yukarı, klasik grid davranışı)
 *
 * Alım tutarı her dipte sabittir (effectiveOrderSize); toplam bütçe tavandır:
 * kalan bütçe bir alımı karşılamıyorsa o tur alım yapılmaz. Merkezî Zarar Durdurma
 * ve Trailing Take-Profit (TradeEngine) tüm pozisyona ek koruma olarak çalışır.
 */
class GridV2Strategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        if ($price <= 0) {
            return ['Grid v2: fiyat alınamadı.'];
        }

        // 1) Çapayı sabitle (yalnız ilk koşuda). Yukarı kaymaz.
        $anchor = (float) ($bot->v2_anchor_price ?? 0);
        if ($anchor <= 0) {
            $anchor = $price;
            $bot->v2_anchor_price = $anchor;
            $bot->save();

            // İlk tur: kesişim referansı yok, kurulumda toplu alım olmaz.
            return ['Grid v2: çapa sabitlendi @ '.kb_price($anchor).' (alım seviyeleri bu fiyatın altına kurulur).'];
        }

        $step = (float) $bot->param('v2_step_pct', 1) / 100;
        if ($step <= 0) {
            return ['Grid v2: adım yüzdesi (v2_step_pct) geçersiz.'];
        }

        $sellPct = (float) $bot->param('sell_profit_pct', 0);
        $sellFactor = 1 + ($sellPct > 0 ? $sellPct / 100 : $step);

        // En derin geçerli seviye: buy_price(k) > 0  →  k < 1/step
        $kFloor = (int) ceil(1 / $step) - 1;
        if ($kFloor < 1) {
            return ['Grid v2: adım çok büyük, geçerli seviye yok.'];
        }

        // Fiyatın ulaştığı en derin seviye indeksi: buy_price(k) >= price
        // anchor(1 - k·step) >= price  ⟺  k <= (1 - price/anchor)/step
        $reach = (int) floor((1 - $price / $anchor) / $step);
        $reach = min($reach, $kFloor);

        $prev = $engine->previousPrice;
        $lines = [];

        // 2) Ulaşılan derinliğe kadar eksik seviye satırlarını oluştur (lazily).
        if ($reach >= 1) {
            $existing = $bot->gridLevels()->pluck('level_index')->all();
            $have = array_flip($existing);
            for ($k = 1; $k <= $reach; $k++) {
                if (isset($have[$k])) {
                    continue;
                }
                $buy = $anchor * (1 - $k * $step);
                if ($buy <= 0) {
                    continue;
                }
                TradeGridLevel::create([
                    'trade_bot_id' => $bot->id,
                    'level_index' => $k,
                    'buy_price' => $buy,
                    'sell_price' => $buy * $sellFactor,
                    'status' => 'waiting_buy',
                    'quantity' => 0,
                    'buy_order_quote' => 0,
                ]);
            }
        }

        // 3) Bütçe tavanı: kalan = efektif bütçe − şu anki maliyet.
        $cap = $engine->effectiveBudget($bot);
        $pos = $bot->position()->first();
        $remaining = $cap > 0 ? max(0.0, $cap - (float) ($pos->cost_basis ?? 0)) : INF;
        $perBuy = $engine->effectiveOrderSize($bot);

        // 4) Tüm seviyeleri tara: alış (aşağı kesişim) / satış (yukarı kesişim).
        $levels = $bot->gridLevels()->get();
        foreach ($levels as $level) {
            if ($level->status === 'waiting_buy') {
                $buyCross = $prev !== null && $prev > $level->buy_price && $price <= $level->buy_price;
                if (! $buyCross) {
                    continue;
                }
                if ($perBuy <= 0 || $remaining + 1e-9 < $perBuy) {
                    $lines[] = "Grid v2: L{$level->level_index} dibine inildi ama bütçe yetmiyor (alım atlandı).";

                    continue;
                }
                $order = $engine->buy($bot, $perBuy, "gridv2_buy_L{$level->level_index}", $price);
                if ($order && $order->quantity > 0) {
                    $level->status = 'holding';
                    $level->quantity = $order->quantity;
                    $level->buy_order_quote = $order->quote_amount;
                    $level->save();
                    $remaining -= (float) $order->quote_amount;
                    $lines[] = "Grid v2 AL L{$level->level_index} @ ".kb_price($price);
                }
            } elseif ($level->status === 'holding') {
                $sellCross = $prev === null
                    ? $price >= $level->sell_price
                    : ($prev < $level->sell_price && $price >= $level->sell_price);
                if (! $sellCross) {
                    continue;
                }
                $order = $engine->sell($bot, $level->quantity, "gridv2_sell_L{$level->level_index}", $price);
                if ($order && $order->quantity > 0) {
                    $level->status = 'waiting_buy';
                    $level->quantity = 0;
                    $level->buy_order_quote = 0;
                    $level->save();
                    $remaining += (float) $order->quote_amount;
                    $lines[] = "Grid v2 SAT L{$level->level_index} @ ".kb_price($price);
                }
            }
        }

        return $lines ?: ['Grid v2: işlem yok (çapa '.kb_price($anchor).', fiyat seviyelere değmedi).'];
    }
}
