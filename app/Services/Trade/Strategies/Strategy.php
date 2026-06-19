<?php

namespace App\Services\Trade\Strategies;

use App\Models\TradeBot;
use App\Services\Trade\TradeEngine;

interface Strategy
{
    /**
     * Stratejiyi tek tur calistirir. Donus: gunluk/sonuc satirlari.
     *
     * @return array<int,string>
     */
    public function run(TradeBot $bot, TradeEngine $engine, float $price): array;
}
