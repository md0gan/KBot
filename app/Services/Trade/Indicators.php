<?php

namespace App\Services\Trade;

/**
 * Basit teknik indikatorler. Kapanis dizileri eskiden yeniye siralidir.
 */
class Indicators
{
    /**
     * Wilder ATR (Average True Range) — volatilite. highs/lows/closes ayni uzunlukta,
     * eskiden yeniye sirali. Yetersiz veri varsa null.
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $n = min(count($highs), count($lows), count($closes));
        if ($n < $period + 1) {
            return null;
        }

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $h = (float) $highs[$i];
            $l = (float) $lows[$i];
            $pc = (float) $closes[$i - 1];
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        if (count($trs) < $period) {
            return null;
        }

        // Ilk ATR = ilk `period` TR ortalamasi; sonra Wilder yumusatma.
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period, $m = count($trs); $i < $m; $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }

        return $atr;
    }

    /** Wilder RSI (son deger). Yetersiz veri varsa null. */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return null;
        }

        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $ch = $closes[$i] - $closes[$i - 1];
            if ($ch >= 0) {
                $gains += $ch;
            } else {
                $losses -= $ch;
            }
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        for ($i = $period + 1; $i < $n; $i++) {
            $ch = $closes[$i] - $closes[$i - 1];
            $g = $ch > 0 ? $ch : 0;
            $l = $ch < 0 ? -$ch : 0;
            $avgGain = ($avgGain * ($period - 1) + $g) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $l) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    /** SMA serisi (closes ile hizali; erken barlar null). */
    public static function smaSeries(array $closes, int $period): array
    {
        $out = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $period - 1) {
                $out[] = null;

                continue;
            }
            $sum = 0.0;
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                $sum += $closes[$j];
            }
            $out[] = $sum / $period;
        }

        return $out;
    }

    /** EMA serisi (ilk deger SMA ile tohumlanir). */
    public static function emaSeries(array $closes, int $period): array
    {
        $out = [];
        $n = count($closes);
        $k = 2 / ($period + 1);
        $ema = null;

        for ($i = 0; $i < $n; $i++) {
            if ($i < $period - 1) {
                $out[] = null;

                continue;
            }
            if ($ema === null) {
                $sum = 0.0;
                for ($j = $i - $period + 1; $j <= $i; $j++) {
                    $sum += $closes[$j];
                }
                $ema = $sum / $period;
            } else {
                $ema = $closes[$i] * $k + $ema * (1 - $k);
            }
            $out[] = $ema;
        }

        return $out;
    }

    /** Tip'e gore MA serisi ('sma' | 'ema'). */
    public static function maSeries(array $closes, int $period, string $type = 'sma'): array
    {
        return $type === 'ema'
            ? self::emaSeries($closes, $period)
            : self::smaSeries($closes, $period);
    }

    /**
     * Kesisim tespiti: kisa MA, uzun MA'yi son barda yukari/asagi kesti mi?
     * Donus: 'bullish' (yukari kesti) | 'bearish' (asagi kesti) | null.
     */
    public static function crossSignal(array $closes, int $short, int $long, string $type = 'sma'): ?string
    {
        $s = self::maSeries($closes, $short, $type);
        $l = self::maSeries($closes, $long, $type);
        $n = count($closes);
        if ($n < 2) {
            return null;
        }

        $sNow = $s[$n - 1] ?? null;
        $lNow = $l[$n - 1] ?? null;
        $sPrev = $s[$n - 2] ?? null;
        $lPrev = $l[$n - 2] ?? null;

        if ($sNow === null || $lNow === null || $sPrev === null || $lPrev === null) {
            return null;
        }

        if ($sPrev <= $lPrev && $sNow > $lNow) {
            return 'bullish';
        }
        if ($sPrev >= $lPrev && $sNow < $lNow) {
            return 'bearish';
        }

        return null;
    }

    /**
     * MACD: macd cizgisi = EMA(fast) - EMA(slow); signal = EMA(macd, signal).
     * Donus: ['macd' => [...], 'signal' => [...]] (closes ile hizali, erken barlar null).
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signalP = 9): array
    {
        $emaFast = self::emaSeries($closes, $fast);
        $emaSlow = self::emaSeries($closes, $slow);
        $n = count($closes);

        $macd = [];
        for ($i = 0; $i < $n; $i++) {
            $macd[$i] = ($emaFast[$i] !== null && $emaSlow[$i] !== null)
                ? $emaFast[$i] - $emaSlow[$i]
                : null;
        }

        $signal = array_fill(0, $n, null);
        $k = 2 / ($signalP + 1);
        $ema = null;
        $seen = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] === null) {
                continue;
            }
            $seen++;
            if ($seen < $signalP) {
                continue;
            }
            if ($ema === null) {
                $vals = [];
                for ($j = $i; $j >= 0 && count($vals) < $signalP; $j--) {
                    if ($macd[$j] !== null) {
                        $vals[] = $macd[$j];
                    }
                }
                $ema = array_sum($vals) / count($vals);
            } else {
                $ema = $macd[$i] * $k + $ema * (1 - $k);
            }
            $signal[$i] = $ema;
        }

        return ['macd' => $macd, 'signal' => $signal];
    }

    /** MACD sinyal kesisimi: 'bullish' | 'bearish' | null. */
    public static function macdCross(array $closes, int $fast = 12, int $slow = 26, int $signalP = 9): ?string
    {
        $r = self::macd($closes, $fast, $slow, $signalP);
        $m = $r['macd'];
        $s = $r['signal'];
        $n = count($closes);
        if ($n < 2) {
            return null;
        }

        $mNow = $m[$n - 1] ?? null;
        $sNow = $s[$n - 1] ?? null;
        $mPrev = $m[$n - 2] ?? null;
        $sPrev = $s[$n - 2] ?? null;
        if ($mNow === null || $sNow === null || $mPrev === null || $sPrev === null) {
            return null;
        }

        if ($mPrev <= $sPrev && $mNow > $sNow) {
            return 'bullish';
        }
        if ($mPrev >= $sPrev && $mNow < $sNow) {
            return 'bearish';
        }

        return null;
    }

    /**
     * Bollinger bantlari (son deger). Yetersiz veri varsa null.
     * Donus: ['upper' => , 'middle' => , 'lower' => ].
     */
    public static function bollinger(array $closes, int $period = 20, float $k = 2.0): ?array
    {
        $n = count($closes);
        if ($n < $period) {
            return null;
        }

        $slice = array_slice($closes, $n - $period, $period);
        $mean = array_sum($slice) / $period;
        $var = 0.0;
        foreach ($slice as $c) {
            $var += ($c - $mean) ** 2;
        }
        $std = sqrt($var / $period);

        return [
            'upper' => $mean + $k * $std,
            'middle' => $mean,
            'lower' => $mean - $k * $std,
        ];
    }

    /**
     * Mum formasyonu tespiti (price action). İki mumdan (önceki + güncel) boğa/ayı
     * sinyali üretir. Her mum [open, high, low, close] dizisi.
     *
     * Desteklenen desenler (opts ile aç/kapa):
     *  - Yutan (engulfing): güncel mumun gövdesi öncekini sarar (boğa/ayı).
     *  - Çekiç / Kuyruklu yıldız (pin bar): uzun alt/üst fitil, küçük gövde.
     *
     * opts: ['engulfing'=>bool, 'pin'=>bool, 'wick_ratio'=>float, 'min_body_pct'=>float]
     * Dönüş: 'bull' | 'bear' | null.
     *
     * @param  array{0:float,1:float,2:float,3:float}  $prev
     * @param  array{0:float,1:float,2:float,3:float}  $cur
     */
    public static function candlePattern(array $prev, array $cur, array $opts = []): ?string
    {
        [$po, , , $pc] = $prev;            // önceki open, close (high/low kullanılmıyor)
        [$o, $h, $l, $c] = $cur;

        $useEngulf = $opts['engulfing'] ?? true;
        $usePin = $opts['pin'] ?? true;
        $wickRatio = (float) ($opts['wick_ratio'] ?? 2.0);
        $minBody = ((float) ($opts['min_body_pct'] ?? 0.1) / 100) * ($c > 0 ? $c : 1);

        $body = abs($c - $o);
        $range = $h - $l;
        $upper = $h - max($o, $c);
        $lower = min($o, $c) - $l;

        // 1) Yutan (gövde bazlı) — anlamlı bir gövde gerektirir.
        if ($useEngulf && $body >= $minBody) {
            // Boğa: önceki ayı, güncel boğa ve güncel gövde öncekini sarar.
            if ($c > $o && $pc < $po && $o <= $pc && $c >= $po) {
                return 'bull';
            }
            // Ayı: önceki boğa, güncel ayı ve güncel gövde öncekini sarar.
            if ($c < $o && $pc > $po && $o >= $pc && $c <= $po) {
                return 'bear';
            }
        }

        // 2) Pin bar (çekiç / kuyruklu yıldız) — tek mum.
        if ($usePin && $body > 0 && $range > 0) {
            // Çekiç: uzun alt fitil, küçük üst fitil → dip reddi (boğa).
            if ($lower >= $wickRatio * $body && $upper <= $body) {
                return 'bull';
            }
            // Kuyruklu yıldız: uzun üst fitil, küçük alt fitil → tepe reddi (ayı).
            if ($upper >= $wickRatio * $body && $lower <= $body) {
                return 'bear';
            }
        }

        return null;
    }
}
