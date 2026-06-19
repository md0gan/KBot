<?php

namespace App\Services\Trade;

/**
 * Backtest motoru: bir stratejiyi gecmis kapanis dizisi uzerinde simule eder.
 * Komisyon (fee) ve kayma (slippage) modeli + nakit/equity egrisi uretir.
 * Gercek emir vermez, DB'ye yazmaz.
 *
 * fee/slip: ondalik (0.001 = %0.1).
 */
class Backtest
{
    public static function run(
        string $strategy,
        array $params,
        array $closes,
        float $budget,
        float $orderSize,
        float $feePct = 0.0,
        float $slipPct = 0.0,
        ?string $interval = null,
        ?array $ohlc = null,
    ): array {
        $closes = array_values(array_filter(array_map('floatval', $closes), fn ($c) => $c > 0));
        $n = count($closes);
        if ($n < 30) {
            return ['error' => 'Yetersiz veri (en az 30 mum gerekli).'];
        }
        $orderSize = $orderSize > 0 ? $orderSize : $budget;

        $sim = match ($strategy) {
            'grid' => self::grid($params, $closes, $budget, $feePct, $slipPct),
            'grid_v2' => self::gridV2($params, $closes, $budget, $orderSize, $feePct, $slipPct, $interval),
            'rsi' => self::single(self::rsiSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'ma_cross' => self::single(self::maSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'macd' => self::single(self::macdSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'bollinger' => self::single(self::bollingerSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'smart_scalp' => self::smartScalp($params, $closes, $orderSize, $feePct, $slipPct),
            'price_action' => self::candleBacktest($params, $ohlc ?? [], $orderSize, $feePct, $slipPct),
            default => ['error' => 'Bilinmeyen strateji.'],
        };
        if (isset($sim['error'])) {
            return $sim;
        }

        $base = $sim['invested'];
        $equity = $sim['equity'];
        $start = $closes[0];
        $end = $closes[$n - 1];
        $finalEq = $equity[$n - 1] ?? $base;
        $totalPl = $finalEq - $base;

        // Al-tut equity (karsilastirma)
        $bh = [];
        for ($i = 0; $i < $n; $i++) {
            $bh[$i] = $base * ($closes[$i] / $start);
        }

        // Grafik icin seyreltme (~180 nokta)
        $step = max(1, (int) ceil($n / 180));
        $labels = [];
        $ce = [];
        $cb = [];
        for ($i = 0; $i < $n; $i += $step) {
            $labels[] = $i;
            $ce[] = round($equity[$i], 2);
            $cb[] = round($bh[$i], 2);
        }
        if (($n - 1) % $step !== 0) {
            $labels[] = $n - 1;
            $ce[] = round($equity[$n - 1], 2);
            $cb[] = round($bh[$n - 1], 2);
        }

        return [
            'bars' => $n,
            'start_price' => $start,
            'end_price' => $end,
            'trades' => $sim['trades'],
            'wins' => $sim['wins'],
            'losses' => $sim['losses'],
            'realized' => $sim['realized'],
            'open_value' => $sim['open_value'],
            'invested' => $base,
            'final_equity' => $finalEq,
            'total_pl' => $totalPl,
            'pl_pct' => $base > 0 ? ($totalPl / $base * 100) : 0,
            'win_rate' => $sim['trades'] > 0 ? ($sim['wins'] / $sim['trades'] * 100) : 0,
            'buy_hold_pct' => $start > 0 ? (($end - $start) / $start * 100) : 0,
            'chart' => ['labels' => $labels, 'equity' => $ce, 'buyhold' => $cb],
        ];
    }

    /* ---- Tek pozisyonlu stratejiler ---- */

    protected static function single(array $signals, array $closes, float $orderSize, float $fee, float $slip): array
    {
        $base = $orderSize;
        $cash = $base;
        $qty = 0.0;
        $entryCost = 0.0;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $n = count($closes);
        $equity = [];

        for ($i = 0; $i < $n; $i++) {
            $price = $closes[$i];
            $sig = $signals[$i] ?? null;

            if ($sig === 'buy' && $qty <= 0 && $cash >= $base) {
                $cash -= $base;
                $entryCost = $base;
                $qty = ($base * (1 - $fee)) / ($price * (1 + $slip));
            } elseif ($sig === 'sell' && $qty > 0) {
                $net = $qty * $price * (1 - $slip) * (1 - $fee);
                $cash += $net;
                $pl = $net - $entryCost;
                $realized += $pl;
                $trades++;
                if ($pl > 0) {
                    $wins++;
                }
                $qty = 0;
                $entryCost = 0;
            }

            $equity[$i] = $cash + $qty * $price;
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $qty * $closes[$n - 1],
            'invested' => $base,
            'equity' => $equity,
        ];
    }

    /**
     * Akilli Scalp: RSI asiri satim + fiyat Bollinger alt bandinda iken AL;
     * sabit kucuk kar hedefi (scalp_tp_pct) VEYA RSI asiri alim olunca SAT.
     * (Backtest'te HTF trend filtresi uygulanmaz — canliya ozeldir.)
     */
    protected static function smartScalp(array $p, array $closes, float $orderSize, float $fee, float $slip): array
    {
        $rsiPeriod = (int) ($p['rsi_period'] ?? 14);
        $oversold = (float) ($p['oversold'] ?? 30);
        $overbought = (float) ($p['overbought'] ?? 60);
        $bbPeriod = (int) ($p['bb_period'] ?? 20);
        $bbK = (float) ($p['bb_k'] ?? 2);
        $tpPct = (float) ($p['scalp_tp_pct'] ?? 0.6);

        $n = count($closes);
        $base = $orderSize;
        $cash = $base;
        $qty = 0.0;
        $entryCost = 0.0;
        $entryPrice = 0.0;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $equity = [];
        $warm = max($rsiPeriod, $bbPeriod) + 1;

        for ($i = 0; $i < $n; $i++) {
            $price = $closes[$i];

            if ($i >= $warm) {
                $slice = array_slice($closes, 0, $i + 1);
                $rsi = Indicators::rsi($slice, $rsiPeriod);
                $bands = Indicators::bollinger($slice, $bbPeriod, $bbK);

                if ($rsi !== null && $bands !== null) {
                    if ($qty <= 0) {
                        if ($rsi <= $oversold && $price <= $bands['lower'] && $cash >= $base) {
                            $cash -= $base;
                            $entryCost = $base;
                            $qty = ($base * (1 - $fee)) / ($price * (1 + $slip));
                            $entryPrice = $price;
                        }
                    } else {
                        $tpHit = $entryPrice > 0 && $price >= $entryPrice * (1 + $tpPct / 100);
                        if ($tpHit || $rsi >= $overbought) {
                            $net = $qty * $price * (1 - $slip) * (1 - $fee);
                            $cash += $net;
                            $pl = $net - $entryCost;
                            $realized += $pl;
                            $trades++;
                            if ($pl > 0) {
                                $wins++;
                            }
                            $qty = 0.0;
                            $entryCost = 0.0;
                            $entryPrice = 0.0;
                        }
                    }
                }
            }

            $equity[$i] = $cash + $qty * $price;
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $qty * $closes[$n - 1],
            'invested' => $base,
            'equity' => $equity,
        ];
    }

    protected static function rsiSignals(array $p, array $closes): array
    {
        $period = (int) ($p['period'] ?? 14);
        $oversold = (float) ($p['oversold'] ?? 30);
        $overbought = (float) ($p['overbought'] ?? 70);
        $n = count($closes);
        $sig = array_fill(0, $n, null);

        for ($i = $period; $i < $n; $i++) {
            $rsi = Indicators::rsi(array_slice($closes, 0, $i + 1), $period);
            if ($rsi === null) {
                continue;
            }
            if ($rsi <= $oversold) {
                $sig[$i] = 'buy';
            } elseif ($rsi >= $overbought) {
                $sig[$i] = 'sell';
            }
        }

        return $sig;
    }

    protected static function maSignals(array $p, array $closes): array
    {
        $short = (int) ($p['short'] ?? 9);
        $long = (int) ($p['long'] ?? 21);
        $type = (string) ($p['ma_type'] ?? 'ema');

        return self::crossSignals(
            Indicators::maSeries($closes, $short, $type),
            Indicators::maSeries($closes, $long, $type),
            count($closes)
        );
    }

    protected static function macdSignals(array $p, array $closes): array
    {
        $fast = (int) ($p['fast'] ?? 12);
        $slow = (int) ($p['slow'] ?? 26);
        $signalP = (int) ($p['signal'] ?? 9);
        $r = Indicators::macd($closes, $fast, $slow, $signalP);
        $sig = self::crossSignals($r['macd'], $r['signal'], count($closes));
        self::applyEntryFilters($sig, $closes, $p, $r['macd']);

        return $sig;
    }

    protected static function bollingerSignals(array $p, array $closes): array
    {
        $period = (int) ($p['period'] ?? 20);
        $k = (float) ($p['k'] ?? 2);
        $n = count($closes);
        $sig = array_fill(0, $n, null);

        for ($i = $period; $i < $n; $i++) {
            $bands = Indicators::bollinger(array_slice($closes, 0, $i + 1), $period, $k);
            if ($bands === null) {
                continue;
            }
            if ($closes[$i] <= $bands['lower']) {
                $sig[$i] = 'buy';
            } elseif ($closes[$i] >= $bands['upper']) {
                $sig[$i] = 'sell';
            }
        }

        // Bollinger RSI onayi (alim icin ekstra asiri-satim teyidi)
        if (! empty($p['confirm_rsi'])) {
            for ($i = 14; $i < $n; $i++) {
                if ($sig[$i] === 'buy') {
                    $rsi = Indicators::rsi(array_slice($closes, 0, $i + 1), 14);
                    if ($rsi === null || $rsi > 40) {
                        $sig[$i] = null;
                    }
                }
            }
        }

        self::applyEntryFilters($sig, $closes, $p);

        return $sig;
    }

    /** Trend MA ve (MACD icin) sifir-cizgisi filtreleri sadece ALIM sinyallerini eler. */
    protected static function applyEntryFilters(array &$sig, array $closes, array $p, ?array $macdLine = null): void
    {
        $n = count($closes);
        $trendMa = (int) ($p['trend_ma'] ?? 0);
        $ema = $trendMa > 1 ? Indicators::emaSeries($closes, $trendMa) : null;
        $reqZero = ! empty($p['require_above_zero']);

        for ($i = 0; $i < $n; $i++) {
            if (($sig[$i] ?? null) !== 'buy') {
                continue;
            }
            if ($ema !== null && ($ema[$i] === null || $closes[$i] < $ema[$i])) {
                $sig[$i] = null;

                continue;
            }
            if ($reqZero && $macdLine !== null && (($macdLine[$i] ?? null) === null || $macdLine[$i] <= 0)) {
                $sig[$i] = null;
            }
        }
    }

    protected static function crossSignals(array $a, array $b, int $n): array
    {
        $sig = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            $aNow = $a[$i] ?? null;
            $bNow = $b[$i] ?? null;
            $aPrev = $a[$i - 1] ?? null;
            $bPrev = $b[$i - 1] ?? null;
            if ($aNow === null || $bNow === null || $aPrev === null || $bPrev === null) {
                continue;
            }
            if ($aPrev <= $bPrev && $aNow > $bNow) {
                $sig[$i] = 'buy';
            } elseif ($aPrev >= $bPrev && $aNow < $bNow) {
                $sig[$i] = 'sell';
            }
        }

        return $sig;
    }

    /* ---- Price action (mum formasyonu) ---- */

    /**
     * Price action backtest: her barda önceki+güncel mumdan Indicators::candlePattern
     * sinyali üretir. Boğa formasyonunda (flat) AL; ayı formasyonu veya sabit kâr hedefi
     * (tp_pct) ile (holding) SAT. Tek pozisyon. OHLC: opens/highs/lows/closes gerekir.
     */
    protected static function candleBacktest(array $p, array $ohlc, float $orderSize, float $fee, float $slip): array
    {
        $opens = $ohlc['opens'] ?? [];
        $highs = $ohlc['highs'] ?? [];
        $lows = $ohlc['lows'] ?? [];
        $closes = $ohlc['closes'] ?? [];
        $n = min(count($opens), count($highs), count($lows), count($closes));
        if ($n < 3) {
            return ['error' => 'Price action: yetersiz/eksik OHLC verisi.'];
        }

        $opts = [
            'engulfing' => $p['pa_engulfing'] ?? true,
            'pin' => $p['pa_pin'] ?? true,
            'wick_ratio' => (float) ($p['wick_ratio'] ?? 2.0),
            'min_body_pct' => (float) ($p['min_body_pct'] ?? 0.1),
        ];
        $tp = max(0.0, (float) ($p['tp_pct'] ?? 0)) / 100;

        $base = $orderSize;
        $cash = $base;
        $qty = 0.0;
        $entryCost = 0.0;
        $entryPrice = 0.0;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $equity = [];

        for ($i = 0; $i < $n; $i++) {
            $price = $closes[$i];

            if ($i >= 1) {
                $sig = Indicators::candlePattern(
                    [$opens[$i - 1], $highs[$i - 1], $lows[$i - 1], $closes[$i - 1]],
                    [$opens[$i], $highs[$i], $lows[$i], $closes[$i]],
                    $opts
                );

                if ($qty <= 0) {
                    if ($sig === 'bull' && $cash >= $base) {
                        $cash -= $base;
                        $entryCost = $base;
                        $qty = ($base * (1 - $fee)) / ($price * (1 + $slip));
                        $entryPrice = $price;
                    }
                } else {
                    $exit = $sig === 'bear' || ($tp > 0 && $entryPrice > 0 && $price >= $entryPrice * (1 + $tp));
                    if ($exit) {
                        $net = $qty * $price * (1 - $slip) * (1 - $fee);
                        $cash += $net;
                        $pl = $net - $entryCost;
                        $realized += $pl;
                        $trades++;
                        if ($pl > 0) {
                            $wins++;
                        }
                        $qty = 0.0;
                        $entryCost = 0.0;
                        $entryPrice = 0.0;
                    }
                }
            }

            $equity[$i] = $cash + $qty * $price;
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $qty * $closes[$n - 1],
            'invested' => $base,
            'equity' => $equity,
        ];
    }

    /* ---- Grid v2 (sabit çapalı dip-alım merdiveni) ---- */

    /** Zaman dilimini dakikaya çevirir (hız limiti penceresini bara çevirmek için). 0 = bilinmiyor. */
    protected static function intervalMinutes(?string $interval): int
    {
        return match ($interval) {
            '1m' => 1, '3m' => 3, '5m' => 5, '15m' => 15, '30m' => 30,
            '1h' => 60, '2h' => 120, '4h' => 240, '6h' => 360, '8h' => 480, '12h' => 720,
            '1d' => 1440, '3d' => 4320, '1w' => 10080,
            default => 0,
        };
    }

    /**
     * Grid v2 backtest: canlı GridV2Strategy ile birebir mantık. Çapa = ilk kapanış;
     * seviyeler çapanın altına doğrusal (buy = çapa·(1−k·step)); fiyat boş bir seviyeye
     * aşağı kesişimle dokununca sabit tutar (orderSize) alır; lot satış hedefine
     * (sell_profit_pct>0 ? alış·(1+%X) : alış·(1+step)) yukarı kesişimle ulaşınca satar.
     * Toplam bütçe nakit tavanıdır (kalan nakit alımı karşılamazsa o seviye atlanır).
     * Hızlı düşüş freni (v2_max_buys/v2_buy_window_h) bar penceresiyle modellenir.
     */
    protected static function gridV2(array $p, array $closes, float $budget, float $orderSize, float $fee, float $slip, ?string $interval = null): array
    {
        $step = (float) ($p['v2_step_pct'] ?? 1) / 100;
        if ($step <= 0) {
            return ['error' => 'Grid v2 adım yüzdesi geçersiz.'];
        }
        $sellPct = (float) ($p['sell_profit_pct'] ?? 0);
        $sellFactor = 1 + ($sellPct > 0 ? $sellPct / 100 : $step);

        $anchor = $closes[0];
        $kFloor = (int) ceil(1 / $step) - 1;   // buy>0 için k < 1/step
        if ($kFloor < 1 || $anchor <= 0) {
            return ['error' => 'Grid v2 adımı çok büyük (geçerli seviye yok).'];
        }

        // Hızlı düşüş freni: pencere (saat) bar sayısına çevrilir. interval yoksa limit yok.
        $maxBuys = (int) ($p['v2_max_buys'] ?? 0);
        $windowH = (float) ($p['v2_buy_window_h'] ?? 4);
        $barMin = self::intervalMinutes($interval);
        $windowBars = ($maxBuys > 0 && $windowH > 0 && $barMin > 0)
            ? max(1, (int) ceil($windowH * 60 / $barMin))
            : 0; // 0 = limit kapalı
        $buyBars = []; // alımların yapıldığı bar indeksleri (kayan pencere)

        $perBuy = $orderSize > 0 ? $orderSize : $budget;
        $base = $budget;
        $cash = $base;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $n = count($closes);
        $equity = [];

        // Seviyeler lazily oluşur: index => ['buy','sell','holding','qty','cost']
        $L = [];

        foreach ($closes as $idx => $price) {
            $prev = $idx > 0 ? $closes[$idx - 1] : null;

            // FLAT (holding yok) iken fiyat çapanın üstüne çıkarsa çapayı yukarı taşı
            // (canlı GridV2Strategy ile birebir). Pozisyon varken çapa donuk kalır.
            $flat = true;
            foreach ($L as $l) {
                if ($l['holding']) {
                    $flat = false;
                    break;
                }
            }
            if ($flat && $price > $anchor) {
                $anchor = $price;
                $L = []; // bayat seviyeleri temizle; yeni çapadan kurulur
            }

            // Fiyatın ulaştığı en derin seviyeye kadar eksikleri oluştur.
            // (+1e-9: tam sınırda kayan nokta yuvarlamasına karşı koruma; canlı ile birebir)
            $reach = min((int) floor((1 - $price / $anchor) / $step + 1e-9), $kFloor);
            for ($k = 1; $k <= $reach; $k++) {
                if (! isset($L[$k])) {
                    $buy = $anchor * (1 - $k * $step);
                    if ($buy > 0) {
                        $L[$k] = ['buy' => $buy, 'sell' => $buy * $sellFactor, 'holding' => false, 'qty' => 0.0, 'cost' => 0.0];
                    }
                }
            }

            // Hız limiti: bu bardaki pencerede kalan alım hakkı.
            $allow = PHP_INT_MAX;
            if ($windowBars > 0) {
                $cut = $idx - $windowBars;
                $recent = 0;
                foreach ($buyBars as $bi) {
                    if ($bi > $cut) {
                        $recent++;
                    }
                }
                $allow = max(0, $maxBuys - $recent);
            }

            foreach ($L as $k => $l) {
                if (! $l['holding']) {
                    $buyCross = $prev !== null && $prev > $l['buy'] && $price <= $l['buy'];
                    if ($buyCross && $allow > 0 && $perBuy > 0 && $cash + 1e-9 >= $perBuy) {
                        $cash -= $perBuy;
                        $L[$k]['holding'] = true;
                        $L[$k]['qty'] = ($perBuy * (1 - $fee)) / ($price * (1 + $slip));
                        $L[$k]['cost'] = $perBuy;
                        $allow--;
                        $buyBars[] = $idx;
                    }
                } else {
                    $sellCross = $prev !== null && $prev < $l['sell'] && $price >= $l['sell'];
                    if ($sellCross) {
                        $net = $l['qty'] * $price * (1 - $slip) * (1 - $fee);
                        $cash += $net;
                        $pl = $net - $l['cost'];
                        $realized += $pl;
                        $trades++;
                        if ($pl > 0) {
                            $wins++;
                        }
                        $L[$k]['holding'] = false;
                        $L[$k]['qty'] = 0.0;
                        $L[$k]['cost'] = 0.0;
                    }
                }
            }

            $hold = 0.0;
            foreach ($L as $l) {
                if ($l['holding']) {
                    $hold += $l['qty'] * $price;
                }
            }
            $equity[$idx] = $cash + $hold;
        }

        $end = $closes[$n - 1];
        $openValue = 0.0;
        foreach ($L as $l) {
            if ($l['holding']) {
                $openValue += $l['qty'] * $end;
            }
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $openValue,
            'invested' => $base,
            'equity' => $equity,
        ];
    }

    /* ---- Grid ---- */

    /**
     * AUTO grid kademeleri (TradeEngine ile ayni matematik): adim yuzdesi kademe basina.
     * sell = buy*(1+pct); ardisik alislar arasi %pct.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    protected static function autoGridPairs(float $price, float $pct, int $levels, string $anchor): array
    {
        $zero = $anchor === 'below' ? $levels : intdiv($levels, 2);
        $down = 1 - $pct;
        $up = 1 + $pct;

        $pairs = [];
        for ($i = 0; $i < $levels; $i++) {
            $buy = $price * ($down ** ($zero - $i));
            $pairs[] = [$buy, $buy * $up];
        }

        return $pairs;
    }

    /** Kapanis bazli ATR yaklasigi (son `period` kapanis-degisimi mutlak ortalamasi). */
    protected static function atrProxy(array $closes, int $period): float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return 0.0;
        }
        $sum = 0.0;
        $cnt = 0;
        for ($i = $n - $period; $i < $n; $i++) {
            if ($i < 1) {
                continue;
            }
            $sum += abs($closes[$i] - $closes[$i - 1]);
            $cnt++;
        }

        return $cnt > 0 ? $sum / $cnt : 0.0;
    }

    /** ATR adimina gore dogrusal (mutlak) kademeler (TradeEngine ile ayni mantik). */
    protected static function atrGridPairs(float $price, float $step, int $levels, string $anchor): array
    {
        if ($step <= 0) {
            return [];
        }
        $zero = $anchor === 'below' ? $levels : intdiv($levels, 2);
        $pairs = [];
        for ($i = 0; $i < $levels; $i++) {
            $buy = $price - $step * ($zero - $i);
            if ($buy <= 0) {
                continue;
            }
            $pairs[] = [$buy, $buy + $step];
        }

        return $pairs;
    }

    /** Sabit satis kari: sell_profit_pct > 0 ise sell = buy*(1+%X) (TradeEngine ile ayni). */
    protected static function applySellProfit(array $pairs, array $p): array
    {
        $sellPct = (float) ($p['sell_profit_pct'] ?? 0);
        if ($sellPct <= 0) {
            return $pairs;
        }
        $f = 1 + $sellPct / 100;

        return array_map(fn ($pr) => [$pr[0], $pr[0] * $f], $pairs);
    }

    protected static function grid(array $p, array $closes, float $budget, float $fee, float $slip): array
    {
        $levels = max(2, (int) ($p['levels'] ?? 5));
        $rangeMode = $p['range_mode'] ?? 'manual';
        $first = $closes[0];

        $anchor = $p['anchor'] ?? 'symmetric';
        if ($rangeMode === 'auto') {
            $pct = max(0.0001, (float) ($p['percent'] ?? 10) / 100);
            // Kademe-basina %step (alis->satis %pct, ardisik alislar %pct).
            $pairs = self::autoGridPairs($first, $pct, $levels, $anchor);
        } elseif ($rangeMode === 'atr') {
            // ATR backtest'te kapanis bazli YAKLASIKLA hesaplanir (canli motor gercek H/L kullanir).
            $period = max(2, (int) ($p['atr_period'] ?? 14));
            $mult = max(0.1, (float) ($p['atr_mult'] ?? 1.0));
            $step = self::atrProxy($closes, $period) * $mult;
            if ($step <= 0) {
                return ['error' => 'ATR hesaplanamadı (yetersiz veri).'];
            }
            $pairs = self::atrGridPairs($first, $step, $levels, $anchor);
        } else {
            $lower = (float) ($p['lower'] ?? 0);
            $upper = (float) ($p['upper'] ?? 0);
            if ($lower <= 0 || $upper <= $lower) {
                return ['error' => 'Grid aralığı geçersiz (alt/üst fiyat girin veya otomatik seçin).'];
            }
            $st = ($upper - $lower) / $levels;
            $pairs = [];
            for ($i = 0; $i < $levels; $i++) {
                $b = $lower + $st * $i;
                $pairs[] = [$b, $b + $st];
            }
        }

        // Sabit satis kari (varsa) tum modlara uygulanir.
        $pairs = self::applySellProfit($pairs, $p);

        $perLevel = $budget / $levels;
        $trailing = (bool) ($p['trailing'] ?? false);

        $L = [];
        foreach ($pairs as [$b, $s]) {
            $L[] = ['buy' => $b, 'sell' => $s, 'holding' => false, 'qty' => 0.0];
        }

        $base = $budget;
        $cash = $base;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $n = count($closes);
        $equity = [];

        foreach ($closes as $idx => $price) {
            $prev = $idx > 0 ? $closes[$idx - 1] : null;

            if ($trailing) {
                $flat = true;
                foreach ($L as $l) {
                    if ($l['holding']) {
                        $flat = false;
                        break;
                    }
                }
                $hi = $L[$levels - 1]['sell'];
                $lo = $L[0]['buy'];
                if ($flat && ($price > $hi || $price < $lo)) {
                    if ($rangeMode === 'auto') {
                        $pairs = self::autoGridPairs($price, $pct, $levels, $anchor);
                    } elseif ($rangeMode === 'atr') {
                        $pairs = self::atrGridPairs($price, $step, $levels, $anchor);
                    } else {
                        $width = $hi - $lo;
                        $nl = max(0.0, $price - $width / 2);
                        $st = $width / $levels;
                        $pairs = [];
                        for ($i = 0; $i < $levels; $i++) {
                            $b = $nl + $st * $i;
                            $pairs[] = [$b, $b + $st];
                        }
                    }
                    $pairs = self::applySellProfit($pairs, $p);
                    $L = [];
                    foreach ($pairs as [$b, $s]) {
                        $L[] = ['buy' => $b, 'sell' => $s, 'holding' => false, 'qty' => 0.0];
                    }
                }
            }

            foreach ($L as $i => $l) {
                $buyCross = $prev !== null && $prev > $l['buy'] && $price <= $l['buy'];
                $sellCross = $prev !== null && $prev < $l['sell'] && $price >= $l['sell'];
                if (! $l['holding'] && $buyCross && $cash >= $perLevel) {
                    $cash -= $perLevel;
                    $L[$i]['holding'] = true;
                    $L[$i]['qty'] = ($perLevel * (1 - $fee)) / ($price * (1 + $slip));
                } elseif ($l['holding'] && $sellCross) {
                    $net = $l['qty'] * $price * (1 - $slip) * (1 - $fee);
                    $cash += $net;
                    $pl = $net - $perLevel;
                    $realized += $pl;
                    $trades++;
                    if ($pl > 0) {
                        $wins++;
                    }
                    $L[$i]['holding'] = false;
                    $L[$i]['qty'] = 0.0;
                }
            }

            $hold = 0.0;
            foreach ($L as $l) {
                if ($l['holding']) {
                    $hold += $l['qty'] * $price;
                }
            }
            $equity[$idx] = $cash + $hold;
        }

        $end = $closes[$n - 1];
        $openValue = 0.0;
        foreach ($L as $l) {
            if ($l['holding']) {
                $openValue += $l['qty'] * $end;
            }
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $openValue,
            'invested' => $base,
            'equity' => $equity,
        ];
    }
}
