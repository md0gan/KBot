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
    ): array {
        $closes = array_values(array_filter(array_map('floatval', $closes), fn ($c) => $c > 0));
        $n = count($closes);
        if ($n < 30) {
            return ['error' => 'Yetersiz veri (en az 30 mum gerekli).'];
        }
        $orderSize = $orderSize > 0 ? $orderSize : $budget;

        $sim = match ($strategy) {
            'grid' => self::grid($params, $closes, $budget, $feePct, $slipPct),
            'rsi' => self::single(self::rsiSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'ma_cross' => self::single(self::maSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'macd' => self::single(self::macdSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
            'bollinger' => self::single(self::bollingerSignals($params, $closes), $closes, $orderSize, $feePct, $slipPct),
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

    /* ---- Grid ---- */

    protected static function grid(array $p, array $closes, float $budget, float $fee, float $slip): array
    {
        $levels = max(2, (int) ($p['levels'] ?? 5));
        $rangeMode = $p['range_mode'] ?? 'manual';
        $first = $closes[0];

        if ($rangeMode === 'auto') {
            $pct = (float) ($p['percent'] ?? 10) / 100;
            $lower = $first * (1 - $pct);
            $upper = (($p['anchor'] ?? 'symmetric') === 'below') ? $first : $first * (1 + $pct);
        } else {
            $lower = (float) ($p['lower'] ?? 0);
            $upper = (float) ($p['upper'] ?? 0);
        }
        if ($lower <= 0 || $upper <= $lower) {
            return ['error' => 'Grid aralığı geçersiz (alt/üst fiyat girin veya otomatik seçin).'];
        }

        $step = ($upper - $lower) / $levels;
        $perLevel = $budget / $levels;
        $trailing = (bool) ($p['trailing'] ?? false);

        $L = [];
        for ($i = 0; $i < $levels; $i++) {
            $b = $lower + $step * $i;
            $L[] = ['buy' => $b, 'sell' => $b + $step, 'holding' => false, 'qty' => 0.0];
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
                    $width = $hi - $lo;
                    $nl = max(0.0, $price - $width / 2);
                    $st = $width / $levels;
                    $L = [];
                    for ($i = 0; $i < $levels; $i++) {
                        $b = $nl + $st * $i;
                        $L[] = ['buy' => $b, 'sell' => $b + $st, 'holding' => false, 'qty' => 0.0];
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
