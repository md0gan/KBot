<?php

if (! function_exists('kb_money')) {
    function kb_money(float|int|string|null $v, int $decimals = 2): string
    {
        return number_format((float) $v, $decimals, ',', '.');
    }
}

if (! function_exists('kb_price')) {
    function kb_price(float|int|string|null $v): string
    {
        $v = (float) $v;
        $decimals = ($v != 0.0 && abs($v) < 1) ? 6 : 2;

        return number_format($v, $decimals, ',', '.');
    }
}

if (! function_exists('kb_qty')) {
    function kb_qty(float|int|string|null $v): string
    {
        $s = number_format((float) $v, 8, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}

if (! function_exists('kb_mult')) {
    function kb_mult(float|int|string|null $v): string
    {
        $s = number_format((float) $v, 2, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }
}

if (! function_exists('kb_reason_label')) {
    /**
     * Trade "reason" kodunu insan-okur Turkce etikete cevirir. Yatirim tarafindaki
     * reason'lar zaten metin oldugundan oldugu gibi doner (default).
     */
    function kb_reason_label(?string $reason): string
    {
        $reason = (string) $reason;
        if (preg_match('/^grid_buy_L(\d+)$/', $reason, $m)) {
            return "Grid kademe {$m[1]} alımı (fiyat aşağı dokundu)";
        }
        if (preg_match('/^grid_sell_L(\d+)$/', $reason, $m)) {
            return "Grid kademe {$m[1]} satışı (satış seviyesine ulaştı)";
        }
        if (preg_match('/^gridv2_buy_L(\d+)$/', $reason, $m)) {
            return "Grid v2 seviye {$m[1]} alımı (çapadan dip seviyesine inildi)";
        }
        if (preg_match('/^gridv2_sell_L(\d+)$/', $reason, $m)) {
            return "Grid v2 seviye {$m[1]} satışı (kâr hedefine ulaştı)";
        }

        return match ($reason) {
            'rsi_buy' => 'RSI aşırı satım → alım',
            'rsi_sell' => 'RSI aşırı alım → satış',
            'ma_buy' => 'MA yukarı kesişimi → alım',
            'ma_sell' => 'MA aşağı kesişimi → satış',
            'macd_buy' => 'MACD yukarı kesişimi → alım',
            'macd_sell' => 'MACD aşağı kesişimi → satış',
            'bb_buy' => 'Bollinger alt bant → alım',
            'bb_sell' => 'Bollinger üst bant → satış',
            'scalp_buy' => 'Akıllı Scalp: onaylı dip → alım',
            'scalp_tp' => 'Akıllı Scalp: kâr hedefi → satış',
            'scalp_rsi' => 'Akıllı Scalp: RSI çıkışı → satış',
            'trailing_tp' => 'Trailing take-profit (kâr koruma) → pozisyon kapatıldı',
            'manual_sell' => 'Manuel satış',
            'manual_buy' => 'Manuel alım',
            default => $reason !== '' ? $reason : '—',
        };
    }
}
