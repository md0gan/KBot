<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binance TR API uc noktalari
    |--------------------------------------------------------------------------
    | base_url     : Imzali (hesap/emir) ve sembol/zaman uc noktalari.
    | market_base  : MAIN (type=1) semboller icin piyasa verisi (fiyat) kaynagi.
    |                Dokumantasyona gore type=1 sembollerde market verisi
    |                api.binance.me uzerinden alinir.
    */
    'base_url' => env('BINANCE_TR_BASE_URL', 'https://www.binance.tr'),
    'market_base_url' => env('BINANCE_TR_MARKET_BASE_URL', 'https://api.binance.me'),
    'recv_window' => (int) env('BINANCE_TR_RECV_WINDOW', 5000),
    'http_timeout' => (int) env('BINANCE_TR_HTTP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Strateji varsayilanlari (yeni coin eklerken on deger olarak kullanilir)
    |--------------------------------------------------------------------------
    */
    'default_mode' => env('BOT_DEFAULT_MODE', 'simulation'), // simulation | live
    'default_quote' => env('BOT_DEFAULT_QUOTE', 'USDT'),
    'default_buy_amount' => 10.0,        // her periyotta harcanacak kote miktar
    'default_interval' => 'weekly',       // hourly | daily | weekly | monthly
    'default_profit_multiplier' => 2.0,   // deger >= carpan x sermaye olunca kar-al
    'default_take_profit_strategy' => 'leave_capital', // leave_capital | fixed_ratio
    'default_sell_ratio' => 0.5,          // fixed_ratio modunda satilacak deger orani

    /*
    |--------------------------------------------------------------------------
    | Enum/sabit tanimlari
    |--------------------------------------------------------------------------
    */
    'intervals' => [
        'hourly' => 'Saatlik',
        'daily' => 'Gunluk',
        'weekly' => 'Haftalik',
        'monthly' => 'Aylik',
    ],

    'modes' => [
        'inherit' => 'Genel ayari kullan',
        'simulation' => 'Simulasyon (kagit)',
        'live' => 'Canli (gercek emir)',
    ],

    'take_profit_strategies' => [
        'leave_capital' => 'Sermayeyi birak, karin tamamini USDT yap',
        'fixed_ratio' => 'Sabit oranda sat (deger yuzdesi)',
    ],

    // Binance TR enum eslesmeleri
    'order_side' => [
        'BUY' => 0,
        'SELL' => 1,
    ],

    'order_type' => [
        'LIMIT' => 1,
        'MARKET' => 2,
        'STOP_LOSS' => 3,
        'STOP_LOSS_LIMIT' => 4,
        'TAKE_PROFIT' => 5,
        'TAKE_PROFIT_LIMIT' => 6,
        'LIMIT_MAKER' => 7,
    ],

    'order_status' => [
        -2 => 'SYSTEM_PROCESSING',
        0 => 'NEW',
        1 => 'PARTIALLY_FILLED',
        2 => 'FILLED',
        3 => 'CANCELED',
        4 => 'PENDING_CANCEL',
        5 => 'REJECTED',
        6 => 'EXPIRED',
    ],
];
