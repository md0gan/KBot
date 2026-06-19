<?php

namespace App\Services\Trade;

/**
 * Basit backtest motoru: bir stratejiyi gecmis kapanis dizisi uzerinde simule
 * eder (gercek emir vermez, DB'ye yazmaz). Kabaca sonuc metrikleri dondurur.
 */
class Backtest
{
    public static function run(string $strategy, array $params, array $closes, float $budget, float $orderSize): array
    {
        $closes = array_values(array_filter(array_map('floatval', $closes), fn ($c) => $c > 0));
        $n = count($closes);
        if ($n < 30) {
            return ['error' => 'Yetersiz veri (en az 30 mum gerekli).'];
        }

        $orderSize = $orderSize > 0 ? $orderSize : $budget;

        $r = match ($strategy) {
            'grid' => self::grid($params, $closes, $budget),
            'rsi' => self::single(self::rsiSignals($params, $closes), $closes, $orderSize),
            'ma_cross' => self::single(self::maSignals($params, $closes), $closes, $orderSize),
            'macd' => self::single(self::macdSignals($params, $closes), $closes, $orderSize),
            'bollinger' => self::single(self::bollingerSignals($params, $closes), $closes, $orderSize),
            default => ['error' => 'Bilinmeyen strateji.'],
        };
        if (isset($r['error'])) {
            return $r;
        }

        $start = $closes[0];
        $end = $closes[$n - 1];
        $r['bars'] = $n;
        $r['start_price'] = $start;
        $r['end_price'] = $end;
        $r['buy_hold_pct'] = $start > 0 ? (($end - $start) / $start * 100) : 0;
        $r['pl_pct'] = $r['invested'] > 0 ? ($r['total_pl'] / $r['invested'] * 100) : 0;
        $r['win_rate'] = $r['trades'] > 0 ? ($r['wins'] / $r['trades'] * 100) : 0;

        return $r;
    }

    /* ---- Tek pozisyonlu stratejiler (rsi/ma/macd/bollinger) ---- */

    /** @param array<int,?string> $signals  her bar icin 'buy'|'sell'|null */
    protected static function single(array $signals, array $closes, float $orderSize): array
    {
        $qty = 0.0;
        $cost = 0.0;
        $realized = 0.0;
        $trades = 0;
        $wins = 0;
        $n = count($closes);

        for ($i = 0; $i < $n; $i++) {
            $price = $closes[$i];
            $sig = $signals[$i] ?? null;
            if ($sig === 'buy' && $qty <= 0) {
                $qty = $orderSize / $price;
                $cost = $orderSize;
            } elseif ($sig === 'sell' && $qty > 0) {
                $pl = ($qty * $price) - $cost;
                $realized += $pl;
                $trades++;
                if ($pl > 0) {
                    $wins++;
                }
                $qty = 0;
                $cost = 0;
            }
        }

        $end = $closes[$n - 1];
        $openValue = $qty * $end;

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $openValue,
            'total_pl' => $realized + ($openValue - $cost),
            'invested' => $orderSize,
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
        $s = Indicators::maSeries($closes, $short, $type);
        $l = Indicators::maSeries($closes, $long, $type);

        return self::crossSignals($s, $l, count($closes));
    }

    protected static function macdSignals(array $p, array $closes): array
    {
        $fast = (int) ($p['fast'] ?? 12);
        $slow = (int) ($p['slow'] ?? 26);
        $signalP = (int) ($p['signal'] ?? 9);
        $r = Indicators::macd($closes, $fast, $slow, $signalP);

        return self::crossSignals($r['macd'], $r['signal'], count($closes));
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

        return $sig;
    }

    /** Iki seriden kesisim sinyalleri uretir. */
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

    protected static function grid(array $p, array $closes, float $budget): array
    {
        $levels = max(2, (int) ($p['levels'] ?? 5));
        $rangeMode = $p['range_mode'] ?? 'manual';
        $first = $closes[0];

        if ($rangeMode === 'auto') {
            $pct = (float) ($p['percent'] ?? 10) / 100;
            $lower = $first * (1 - $pct);
            $upper = $first * (1 + $pct);
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

        $realized = 0.0;
        $trades = 0;
        $wins = 0;

        foreach ($closes as $price) {
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

            foreach ($L as $idx => $l) {
                if (! $l['holding'] && $price <= $l['buy']) {
                    $L[$idx]['holding'] = true;
                    $L[$idx]['qty'] = $perLevel / $price;
                } elseif ($l['holding'] && $price >= $l['sell']) {
                    $pl = ($l['qty'] * $price) - $perLevel;
                    $realized += $pl;
                    $trades++;
                    if ($pl > 0) {
                        $wins++;
                    }
                    $L[$idx]['holding'] = false;
                    $L[$idx]['qty'] = 0.0;
                }
            }
        }

        $end = $closes[count($closes) - 1];
        $openValue = 0.0;
        $openCost = 0.0;
        foreach ($L as $l) {
            if ($l['holding']) {
                $openValue += $l['qty'] * $end;
                $openCost += $perLevel;
            }
        }

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $trades - $wins,
            'realized' => $realized,
            'open_value' => $openValue,
            'total_pl' => $realized + ($openValue - $openCost),
            'invested' => $budget,
        ];
    }
}
