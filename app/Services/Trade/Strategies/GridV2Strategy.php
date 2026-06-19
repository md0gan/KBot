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
 * çalıştığı andaki fiyat ÇAPA olur ve alım seviyeleri bu çapanın altına DOĞRUSAL
 * yerleşir:
 *
 *     buy_price(k) = çapa × (1 − k·x)      (k = 1, 2, 3, …)
 *
 * Fiyat, henüz "dolu" olmayan bir seviyenin alış fiyatına yukarıdan aşağı dokununca
 * (kesişim) o seviyeden bir alım yapılır; seviye "dolu" olur ve lot satılana kadar
 * tekrar alım yapmaz. Lot satılınca seviye yeniden "boş"a döner; fiyat o seviyeye
 * tekrar inerse yeniden alır.
 *
 * ÇAPA İZLEME (flat): Açık pozisyon YOKKEN fiyat çapanın üstüne çıkarsa çapa o fiyata
 * yükseltilir (aşağı inmez). Böylece fiyat sürekli yükselip ilk çapanın altına hiç
 * inmese bile bot boşta kalmaz; ilk alım güncel zirveden %adım geri çekilmede tetiklenir.
 * POZİSYON VARKEN çapa DONUKtur: seviyeler sabit mutlak fiyata çakılı kalır, böylece
 * küçük (%adım altı) salınımlar boş yere alım yaptırmaz; biriktirme/satış sabit
 * seviyelerde sürer.
 *
 * Satış hedefi (her lot için):
 *   - sell_profit_pct > 0  → alış × (1 + sell_profit_pct/100)
 *   - sell_profit_pct = 0  → alış × (1 + x)  (bir adım yukarı, klasik grid davranışı)
 *
 * Alım tutarı her dipte sabittir (effectiveOrderSize); toplam bütçe tavandır:
 * kalan bütçe bir alımı karşılamıyorsa o tur alım yapılmaz. Merkezî Zarar Durdurma
 * ve Trailing Take-Profit (TradeEngine) tüm pozisyona ek koruma olarak çalışır.
 *
 * HIZLI DÜŞÜŞ FRENİ (opsiyonel): v2_max_buys > 0 ise pencerede (v2_buy_window_h saat)
 * en fazla v2_max_buys alıma izin verilir. Limit dolunca o tur yeni alım yapılmaz
 * (satışlar etkilenmez), böylece sert bir çöküşte bütçe tek seferde değil kademeli
 * yatırılır ("düşen bıçağı tutma" riskini azaltır).
 */
class GridV2Strategy implements Strategy
{
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array
    {
        if ($price <= 0) {
            return ['Grid v2: fiyat alınamadı.'];
        }

        // 1) Çapa: ilk koşuda o anki fiyata sabitlenir.
        $anchor = (float) ($bot->v2_anchor_price ?? 0);
        if ($anchor <= 0) {
            $anchor = $price;
            $bot->v2_anchor_price = $anchor;
            $bot->save();

            // İlk tur: kesişim referansı yok, kurulumda toplu alım olmaz.
            return ['Grid v2: çapa sabitlendi @ '.kb_price($anchor).' (alım seviyeleri bu fiyatın altına kurulur).'];
        }

        $pos = $bot->position()->first();

        // 2) FLAT (açık pozisyon yok) iken fiyat çapanın üstüne çıkarsa çapayı yukarı taşı.
        // Aksi halde fiyat sürekli yükselip ilk çapanın altına hiç inmezse bot sonsuza
        // dek boşta kalırdı. Çapa yukarı izlenir; ilk alım, güncel zirveden %adım geri
        // çekilince tetiklenir. POZİSYON VARKEN çapa DONUKtur (anti-salınım korunur;
        // biriktirme/satış sabit seviyelerde sürer).
        $hasHolding = $bot->gridLevels()->where('status', 'holding')->exists()
            || (float) ($pos?->quantity ?? 0) > 1e-9;
        if (! $hasHolding && $price > $anchor) {
            $anchor = $price;
            $bot->v2_anchor_price = $anchor;
            $bot->save();
            $bot->gridLevels()->delete(); // bayat bekleyen seviyeleri temizle; yeni çapadan kurulur
            return ['Grid v2: çapa yukarı güncellendi @ '.kb_price($anchor).' (açık işlem yok, fiyat yükseldi).'];
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
        // (+1e-9: tam sınırda kayan nokta yuvarlamasına karşı seviyenin oluşmasını garanti eder)
        $reach = (int) floor((1 - $price / $anchor) / $step + 1e-9);
        $reach = min($reach, $kFloor);

        $prev = $engine->previousPrice;
        $lines = [];

        // 3) Ulaşılan derinliğe kadar eksik seviye satırlarını oluştur (lazily).
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

        // 4) Bütçe tavanı: kalan = efektif bütçe − şu anki maliyet.
        $cap = $engine->effectiveBudget($bot);
        $remaining = $cap > 0 ? max(0.0, $cap - (float) ($pos?->cost_basis ?? 0)) : INF;
        $perBuy = $engine->effectiveOrderSize($bot);

        // 4b) Hızlı düşüş freni: pencerede (son X saat) en fazla N alıma izin ver.
        // v2_max_buys <= 0 → limit kapalı. Limit dolduğunda bu tur yeni alım yapılmaz
        // (satışlar etkilenmez), böylece crash'te bütçe kademeli yatırılır.
        $maxBuys = (int) $bot->param('v2_max_buys', 0);
        $windowH = (float) $bot->param('v2_buy_window_h', 4);
        $allowance = PHP_INT_MAX;
        if ($maxBuys > 0 && $windowH > 0) {
            $recent = $bot->orders()
                ->where('side', 'BUY')
                ->where('executed_at', '>=', now()->subMinutes((int) round($windowH * 60)))
                ->count();
            $allowance = max(0, $maxBuys - $recent);
        }
        $skippedByLimit = 0;

        // 5) Tüm seviyeleri tara: alış (aşağı kesişim) / satış (yukarı kesişim).
        $levels = $bot->gridLevels()->get();
        foreach ($levels as $level) {
            if ($level->status === 'waiting_buy') {
                $buyCross = $prev !== null && $prev > $level->buy_price && $price <= $level->buy_price;
                if (! $buyCross) {
                    continue;
                }
                if ($allowance <= 0) {
                    $skippedByLimit++; // hız limiti dolu: bu seviyeyi atla (satışlar sürer)

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
                    $allowance--;
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

        if ($skippedByLimit > 0) {
            $lines[] = "Grid v2: hız limiti — {$skippedByLimit} seviye atlandı (son ".rtrim(rtrim(number_format($windowH, 1, '.', ''), '0'), '.')." saatte en fazla {$maxBuys} alım).";
        }

        return $lines ?: ['Grid v2: işlem yok (çapa '.kb_price($anchor).', fiyat seviyelere değmedi).'];
    }
}
