<?php

namespace App\Services\Trade;

use App\Models\Setting;
use App\Models\TradeBot;
use App\Models\TradeGridLevel;
use App\Models\TradeOrder;
use App\Models\User;
use App\Services\BinanceTrClient;
use App\Services\BinanceTrException;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\DB;

/**
 * TRADE/SCALP motoru. Yatirim motorundan (TradingBot) tamamen bagimsizdir;
 * ayri tablolara (trade_*) yazar. BinanceTrClient ve TelegramNotifier yeniden
 * kullanilir (onlar degistirilmez).
 */
class TradeEngine
{
    public User $user;
    public Setting $setting;
    protected BinanceTrClient $client;
    protected TelegramNotifier $notifier;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->setting = $user->settings();
        $this->client = BinanceTrClient::fromSetting($this->setting);
        $this->notifier = TelegramNotifier::fromSetting($this->setting);
    }

    public function client(): BinanceTrClient
    {
        return $this->client;
    }

    public function globalMode(): string
    {
        return $this->setting->trading_mode ?? 'simulation';
    }

    /* ======================================================================
     | Calistirma
     * ==================================================================== */

    public function runAll(): array
    {
        if (! $this->setting->bot_enabled) {
            return ['Bot bu kullanici icin kapali (Ayarlar).'];
        }

        $results = [];
        foreach ($this->user->tradeBots()->enabled()->with('position')->get() as $bot) {
            try {
                foreach ($this->run($bot) as $line) {
                    $results[] = "[{$bot->symbol}/{$bot->strategy}] {$line}";
                }
            } catch (\Throwable $e) {
                $this->notifyError("Trade {$bot->symbol}: ".$e->getMessage());
                $results[] = "[HATA {$bot->symbol}] ".$e->getMessage();
            }
        }

        return $results ?: ['Etkin trade botu yok.'];
    }

    public function run(TradeBot $bot): array
    {
        $price = $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
        if ($price <= 0) {
            return ['fiyat alinamadi'];
        }

        $this->updateValuation($bot, $price);

        $strategy = StrategyFactory::make($bot->strategy);
        $lines = $strategy->run($bot, $this, $price);

        $bot->last_run_at = now();
        $bot->save();

        return $lines;
    }

    protected function updateValuation(TradeBot $bot, float $price): void
    {
        $pos = $bot->position()->firstOrCreate([]);
        $pos->last_price = $price;
        $pos->last_value = $pos->quantity * $price;
        $pos->last_valued_at = now();
        $pos->save();
    }

    /* ======================================================================
     | Al / Sat (sim & canli) - trade_orders'a yazar
     * ==================================================================== */

    public function buy(TradeBot $bot, float $quoteAmount, string $reason, ?float $price = null): ?TradeOrder
    {
        $qp = $bot->quote_precision ?? 2;
        $factor = 10 ** $qp;
        $quoteAmount = floor($quoteAmount * $factor) / $factor;
        if ($quoteAmount <= 0) {
            return null;
        }

        $price = $price ?: $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
        if ($price <= 0) {
            return null;
        }

        // Fiyat filtresi (trade modunda da gecerli)
        if ($bot->max_buy_price && $price > (float) $bot->max_buy_price) {
            return null;
        }
        // Borsa minimumlari
        if ($bot->min_notional && $quoteAmount < (float) $bot->min_notional) {
            return null;
        }
        if ($bot->min_qty && ($quoteAmount / $price) < (float) $bot->min_qty) {
            return null;
        }

        $mode = $bot->effectiveMode($this->globalMode());

        if ($mode === 'live') {
            $fill = $this->client->marketBuyQuote($bot->symbol, $quoteAmount);
            $qty = (float) $fill['quantity'];
            $quote = (float) $fill['quote_amount'];
            $execPrice = (float) ($fill['price'] ?: $price);
            $status = $fill['status'];
            $orderId = $fill['order_id'] ?? null;
            $raw = $fill['raw'] ?? null;
            if ($qty <= 0) {
                throw new BinanceTrException("Trade alim emri dolmadi (status: {$status}).");
            }
        } else {
            $qty = $quoteAmount / $price;
            $quote = $quoteAmount;
            $execPrice = $price;
            $status = 'SIMULATED';
            $orderId = null;
            $raw = null;
        }

        $order = DB::transaction(function () use ($bot, $reason, $mode, $qty, $quote, $execPrice, $status, $orderId, $raw) {
            $pos = $bot->position()->lockForUpdate()->first();
            $pos->quantity += $qty;
            $pos->cost_basis += $quote;
            $pos->avg_price = $pos->quantity > 0 ? $pos->cost_basis / $pos->quantity : 0;
            $pos->last_price = $execPrice;
            $pos->last_value = $pos->quantity * $execPrice;
            $pos->last_valued_at = now();
            $pos->trades_count += 1;
            $pos->save();

            return TradeOrder::create([
                'user_id' => $bot->user_id,
                'trade_bot_id' => $bot->id,
                'symbol' => $bot->symbol,
                'side' => 'BUY',
                'strategy' => $bot->strategy,
                'mode' => $mode,
                'quantity' => $qty,
                'price' => $execPrice,
                'quote_amount' => $quote,
                'reason' => $reason,
                'status' => $status,
                'order_id' => $orderId,
                'raw' => $raw,
                'executed_at' => now(),
            ]);
        });

        $this->notifyOrder($bot, $order);

        return $order;
    }

    public function sell(TradeBot $bot, float $quantity, string $reason, ?float $price = null): ?TradeOrder
    {
        $pos = $bot->position()->firstOrCreate([]);
        $quantity = $this->roundQty($bot, min($quantity, $pos->quantity));
        if ($quantity <= 0) {
            return null;
        }

        $price = $price ?: $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
        if ($price <= 0) {
            return null;
        }

        $mode = $bot->effectiveMode($this->globalMode());

        if ($mode === 'live') {
            $fill = $this->client->marketSellQuantity($bot->symbol, $quantity);
            $soldQty = (float) $fill['quantity'];
            $proceeds = (float) $fill['quote_amount'];
            $execPrice = (float) ($fill['price'] ?: $price);
            $status = $fill['status'];
            $orderId = $fill['order_id'] ?? null;
            $raw = $fill['raw'] ?? null;
            if ($soldQty <= 0) {
                throw new BinanceTrException("Trade satis emri dolmadi (status: {$status}).");
            }
        } else {
            $soldQty = $quantity;
            $proceeds = $quantity * $price;
            $execPrice = $price;
            $status = 'SIMULATED';
            $orderId = null;
            $raw = null;
        }

        $order = DB::transaction(function () use ($bot, $reason, $mode, $soldQty, $proceeds, $execPrice, $status, $orderId, $raw) {
            $locked = $bot->position()->lockForUpdate()->first();
            $share = $locked->quantity > 0 ? min(1.0, $soldQty / $locked->quantity) : 0;
            $costOfSold = $locked->cost_basis * $share;
            $realized = $proceeds - $costOfSold;

            $locked->quantity = max(0, $locked->quantity - $soldQty);
            $locked->cost_basis = max(0, $locked->cost_basis - $costOfSold);
            $locked->avg_price = $locked->quantity > 0 ? $locked->cost_basis / $locked->quantity : 0;
            $locked->realized_profit += $realized;
            $locked->last_price = $execPrice;
            $locked->last_value = $locked->quantity * $execPrice;
            $locked->last_valued_at = now();
            $locked->trades_count += 1;
            $locked->save();

            return TradeOrder::create([
                'user_id' => $bot->user_id,
                'trade_bot_id' => $bot->id,
                'symbol' => $bot->symbol,
                'side' => 'SELL',
                'strategy' => $bot->strategy,
                'mode' => $mode,
                'quantity' => $soldQty,
                'price' => $execPrice,
                'quote_amount' => $proceeds,
                'realized_profit' => $realized,
                'reason' => $reason,
                'status' => $status,
                'order_id' => $orderId,
                'raw' => $raw,
                'executed_at' => now(),
            ]);
        });

        $this->notifyOrder($bot, $order);

        return $order;
    }

    /* ======================================================================
     | Grid kademeleri
     * ==================================================================== */

    public function ensureGridLevels(TradeBot $bot): void
    {
        if (! $bot->gridLevels()->exists()) {
            $this->buildGrid($bot);
        }
    }

    public function buildGrid(TradeBot $bot): bool
    {
        $params = $bot->params ?? [];
        $levels = max(2, (int) ($params['levels'] ?? 5));
        $rangeMode = $params['range_mode'] ?? 'manual';

        if ($rangeMode === 'auto') {
            $price = $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
            if ($price <= 0) {
                return false;
            }
            $pct = (float) ($params['percent'] ?? 10) / 100;
            $lower = $price * (1 - $pct);
            $upper = $price * (1 + $pct);
        } else {
            $lower = (float) ($params['lower'] ?? 0);
            $upper = (float) ($params['upper'] ?? 0);
        }

        if ($lower <= 0 || $upper <= $lower) {
            return false;
        }

        $step = ($upper - $lower) / $levels;
        $bot->gridLevels()->delete();
        for ($i = 0; $i < $levels; $i++) {
            $buy = $lower + $step * $i;
            TradeGridLevel::create([
                'trade_bot_id' => $bot->id,
                'level_index' => $i,
                'buy_price' => $buy,
                'sell_price' => $buy + $step,
                'status' => 'waiting_buy',
                'quantity' => 0,
                'buy_order_quote' => 0,
            ]);
        }

        return true;
    }

    /* ======================================================================
     | Sembol senkronu (tek bot)
     * ==================================================================== */

    public function syncSymbol(TradeBot $bot): bool
    {
        $symbol = strtoupper($bot->symbol);
        foreach ($this->client->getSymbols() as $s) {
            if (strtoupper((string) data_get($s, 'symbol')) !== $symbol) {
                continue;
            }
            $filters = collect((array) data_get($s, 'filters', []));
            $lot = $filters->firstWhere('filterType', 'LOT_SIZE');
            $notional = $filters->firstWhere('filterType', 'NOTIONAL')
                ?? $filters->firstWhere('filterType', 'MIN_NOTIONAL');

            $bot->symbol_type = (int) data_get($s, 'type', $bot->symbol_type);
            $bot->base_precision = (int) data_get($s, 'basePrecision', $bot->base_precision);
            $bot->quote_precision = (int) data_get($s, 'quotePrecision', $bot->quote_precision);
            if ($lot) {
                $bot->min_qty = (float) data_get($lot, 'minQty');
                $bot->step_size = (float) data_get($lot, 'stepSize');
            }
            if ($notional) {
                $bot->min_notional = (float) data_get($notional, 'minNotional');
            }
            $bot->last_synced_at = now();
            $bot->save();

            return true;
        }

        return false;
    }

    /* ======================================================================
     | Altyapi
     * ==================================================================== */

    protected function roundQty(TradeBot $bot, float $qty): float
    {
        $step = (float) ($bot->step_size ?? 0);
        if ($step > 0) {
            return floor($qty / $step) * $step;
        }
        if ($bot->base_precision !== null) {
            $f = 10 ** $bot->base_precision;

            return floor($qty * $f) / $f;
        }

        return $qty;
    }

    protected function notifyOrder(TradeBot $bot, TradeOrder $order): void
    {
        if (! $this->notifier->notifyTrades) {
            return;
        }

        $emoji = $order->side === 'BUY' ? '🟢' : '💰';
        $modeLabel = $order->mode === 'live' ? 'CANLI' : 'sim';

        $lines = [
            "{$emoji} [Trade/{$bot->strategyLabel()}] {$order->side} — {$bot->symbol} ({$modeLabel})",
            "Miktar: ".kb_qty($order->quantity)." {$bot->base_asset}",
            "Fiyat: ".kb_price($order->price)." {$bot->quote_asset}",
            "Tutar: ".kb_money($order->quote_amount)." {$bot->quote_asset}",
        ];
        if ($order->realized_profit != 0.0) {
            $lines[] = 'K/Z: '.($order->realized_profit >= 0 ? '+' : '').kb_money($order->realized_profit)." {$bot->quote_asset}";
        }

        $this->notifier->send(implode("\n", $lines));
    }

    protected function notifyError(string $message): void
    {
        if ($this->notifier->notifyErrors) {
            $this->notifier->send("⚠️ Hata — {$message}");
        }
    }
}
