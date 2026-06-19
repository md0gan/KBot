<?php

namespace App\Services\Trade;

use App\Services\Trade\Strategies\BollingerStrategy;
use App\Services\Trade\Strategies\GridStrategy;
use App\Services\Trade\Strategies\MacdStrategy;
use App\Services\Trade\Strategies\MaCrossStrategy;
use App\Services\Trade\Strategies\RsiStrategy;
use App\Services\Trade\Strategies\SmartScalpStrategy;
use App\Services\Trade\Strategies\Strategy;
use InvalidArgumentException;

class StrategyFactory
{
    public static function make(string $strategy): Strategy
    {
        return match ($strategy) {
            'grid' => new GridStrategy(),
            'rsi' => new RsiStrategy(),
            'ma_cross' => new MaCrossStrategy(),
            'macd' => new MacdStrategy(),
            'bollinger' => new BollingerStrategy(),
            'smart_scalp' => new SmartScalpStrategy(),
            default => throw new InvalidArgumentException("Bilinmeyen strateji: {$strategy}"),
        };
    }
}
