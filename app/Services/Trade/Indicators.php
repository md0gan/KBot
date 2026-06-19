<?php

namespace App\Services\Trade;

/**
 * Basit teknik indikatorler. Kapanis dizileri eskiden yeniye siralidir.
 */
class Indicators
{
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
}
