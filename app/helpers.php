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
