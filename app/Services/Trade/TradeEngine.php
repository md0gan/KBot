<?php

namespace App\Services\Trade;

use App\Models\AppSetting;
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
    public ?float $previousPrice = null;   // bir onceki tur fiyati (grid kesisim tespiti)
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

    /** Compounding acikken bütçeye gerceklesen kar/zarari ekler (yoksa sabit). */
    public function effectiveBudget(TradeBot $bot): float
    {
        $budget = (float) $bot->budget;
        if ($bot->param('compounding', false)) {
            $realized = (float) ($bot->position?->realized_profit ?? 0);

            return max(0.0, $budget + $realized);
        }

        return $budget;
    }

    /** Compounding acikken islem tutarini gerceklesen kar/zararla oranli buyutur/kucultur. */
    public function effectiveOrderSize(TradeBot $bot): float
    {
        $size = (float) $bot->order_size;
        if ($size <= 0) {
            $size = (float) $bot->budget; // bos birakildiysa butcenin tamami (RSI/MA)
        }
        if ($bot->param('compounding', false) && $bot->budget > 0) {
            $realized = (float) ($bot->position?->realized_profit ?? 0);
            $factor = max(0.0, ((float) $bot->budget + $realized) / (float) $bot->budget);

            return $size * $factor;
        }

        return $size;
    }

    /**
     * Ust zaman dilimi (HTF) trend onayi: htf_ma >= 2 ise htf_interval kapanislari
     * uzerinde EMA(htf_ma) hesaplar; son kapanis EMA'nin >= ustundeyse trend YUKARI
     * sayilir (alima izin). Filtre kapali veya veri yetersizse true (engelleme yok).
     * Indikator stratejileri alim oncesi bunu cagirir; grid cagirmaz.
     */
    public function htfTrendOk(TradeBot $bot): bool
    {
        $ma = (int) $bot->param('htf_ma', 0);
        if ($ma < 2) {
            return true; // filtre kapali
        }

        $interval = (string) $bot->param('htf_interval', '4h');
        $closes = $this->client->getCloses($bot->symbol, $interval, $ma + 80, $bot->symbol_type ?? 1);
        if (count($closes) < $ma + 1) {
            return true; // veri yetersiz -> engelleme yapma
        }

        $ema = Indicators::emaSeries($closes, $ma);
        $lastEma = null;
        for ($i = count($ema) - 1; $i >= 0; $i--) {
            if ($ema[$i] !== null) {
                $lastEma = (float) $ema[$i];
                break;
            }
        }
        if ($lastEma === null) {
            return true;
        }

        return (float) end($closes) >= $lastEma;
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
        $errors = 0;
        $lastError = '';
        foreach ($this->user->tradeBots()->enabled()->with('position')->get() as $bot) {
            try {
                foreach ($this->run($bot) as $line) {
                    $results[] = "[{$bot->symbol}/{$bot->strategy}] {$line}";
                }
            } catch (\Throwable $e) {
                $errors++;
                $lastError = "{$bot->symbol}: ".$e->getMessage();
                $results[] = "[HATA {$bot->symbol}] ".$e->getMessage();
            }
        }

        // Ust uste hata takibi: her turda spam yerine esikte/toparlamada tek bildirim.
        $errors > 0 ? $this->recordApiError($lastError) : $this->recordApiRecovered();

        return $results ?: ['Etkin trade botu yok.'];
    }

    public function run(TradeBot $bot): array
    {
        $price = $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
        if ($price <= 0) {
            return ['fiyat alinamadi'];
        }

        // Onceki tur fiyatini (kesisim tespiti icin) valuation guncellemeden ONCE yakala
        $this->previousPrice = $bot->position()->first()?->last_price;

        $this->updateValuation($bot, $price);

        // Zarar durdurma: toplam K/Z bütçenin -%X altina dustuyse botu durdur
        if ($stop = $this->checkStopLoss($bot, $price)) {
            return [$stop];
        }

        // Trailing take-profit: toplam K/Z zirveden %X geri cekilince pozisyonu kapat
        if ($tp = $this->checkTrailingTakeProfit($bot, $price)) {
            return [$tp];
        }

        $strategy = StrategyFactory::make($bot->strategy);
        $lines = $strategy->run($bot, $this, $price);

        $bot->last_run_at = now();
        $bot->save();

        return $lines;
    }

    /** Toplam (gerceklesen + acik) K/Z bütçenin -%X altina dustuyse botu durdurur. */
    protected function checkStopLoss(TradeBot $bot, float $price): ?string
    {
        $pct = (float) $bot->param('max_loss_pct', 0);
        if ($pct <= 0 || $bot->budget <= 0) {
            return null;
        }

        $pos = $bot->position()->first();
        if (! $pos) {
            return null;
        }

        $realized = (float) $pos->realized_profit;
        $unrealized = $pos->quantity * $price - (float) $pos->cost_basis;
        $totalPl = $realized + $unrealized;
        $threshold = -($pct / 100) * (float) $bot->budget;

        if ($totalPl <= $threshold) {
            $bot->enabled = false;
            $bot->last_signal = 'DURDURULDU (zarar eşiği %'.rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').')';
            $bot->save();

            if ($this->notifier->notifyErrors) {
                $this->notifier->send(
                    "🛑 [Trade] {$bot->symbol} DURDURULDU — zarar eşiği aşıldı.\n".
                    'Toplam K/Z: '.kb_money($totalPl)." {$bot->quote_asset} (eşik: bütçenin %{$pct}'i)."
                );
            }

            return 'Zarar durdurma: bot durduruldu (toplam K/Z '.kb_money($totalPl)." {$bot->quote_asset}).";
        }

        return null;
    }

    /**
     * Canli trade botlarinin bütçelerini karsilamak icin gereken kote (TRY) bakiyesi,
     * mevcut serbest bakiyeden fazlaysa Telegram'dan uyarir. Kote varligi basina gruplar;
     * "gereken" = her botun henuz dagitilmamis (kalan) bütçesi (budget - mevcut maliyet),
     * yani kurulumda toplam bütçeye, dagittikca azalan tutara esittir.
     *
     * Tekrar spam'i onlemek icin yalnizca durum degisiminde (yeterli<->yetersiz) bir kez bildirir.
     *
     * @return array<int, array{quote: string, required: float, free: float, short: bool}>|null
     */
    public function checkBudgetCoverage(): ?array
    {
        if (! $this->client->hasCredentials()) {
            return null; // canli anahtar yok; bakiye okunamaz
        }

        // Yalnizca ETKIN ve etkin modu CANLI olan botlar gercek bakiye gerektirir.
        $bots = $this->user->tradeBots()->enabled()->with('position')->get()
            ->filter(fn ($b) => $b->effectiveMode($this->globalMode()) === 'live');

        if ($bots->isEmpty()) {
            return null;
        }

        // Kote varligi basina kalan (henuz harcanmamis) bütçeyi topla.
        $required = [];
        foreach ($bots as $bot) {
            $quote = $bot->quote_asset ?: 'TRY';
            $spent = (float) ($bot->position?->cost_basis ?? 0);
            $remaining = max(0.0, (float) $bot->budget - $spent);
            $required[$quote] = ($required[$quote] ?? 0) + $remaining;
        }

        $results = [];
        foreach ($required as $quote => $need) {
            if ($need <= 0) {
                continue;
            }

            try {
                $bal = $this->client->getAssetBalance($quote);
            } catch (\Throwable $e) {
                continue;
            }

            $free = (float) ($bal['free'] ?? 0);
            $short = $free < ($need - 0.00000001);
            $results[] = ['quote' => $quote, 'required' => $need, 'free' => $free, 'short' => $short];

            if (! $this->notifier->notifyBalance) {
                continue; // bakiye bildirimleri kapali
            }

            // Durum degisiminde bir kez bildir (AppSetting'te bayrak tutulur).
            $flagKey = "trade_budget_short_{$this->user->id}_{$quote}";
            $wasShort = AppSetting::get($flagKey) === '1';

            if ($short && ! $wasShort) {
                AppSetting::put($flagKey, '1');
                $deficit = $need - $free;
                $this->notifier->send(
                    "⚠️ Trade bütçesi için yetersiz {$quote} bakiyesi.\n".
                    'Gerekli (kalan bütçe): '.kb_money($need)." {$quote}\n".
                    'Mevcut serbest: '.kb_money($free)." {$quote}\n".
                    'Eksik: '.kb_money($deficit)." {$quote}\n".
                    "Botlar bütçelerini tam dolduramayabilir; {$quote} ekleyin veya bütçeleri düşürün."
                );
            } elseif (! $short && $wasShort) {
                AppSetting::forget($flagKey);
                $this->notifier->send("✅ {$quote} bakiyesi trade bütçeleri için yeniden yeterli (".kb_money($free)." {$quote}).");
            }
        }

        return $results ?: null;
    }

    /**
     * Trailing take-profit (kar koruma): toplam K/Z (gerceklesen + acik) bir zirve
     * yaptiktan sonra butcenin %X'i kadar geri cekilirse pozisyonu TAMAMEN kapatir,
     * kari bankaya yazar ve zirveyi sifirlar. Bot calismaya devam eder (yeniden girer).
     */
    protected function checkTrailingTakeProfit(TradeBot $bot, float $price): ?string
    {
        $pct = (float) $bot->param('trail_tp_pct', 0);
        if ($pct <= 0 || $bot->budget <= 0) {
            return null;
        }

        $pos = $bot->position()->first();
        if (! $pos) {
            return null;
        }

        $realized = (float) $pos->realized_profit;
        $unrealized = $pos->quantity * $price - (float) $pos->cost_basis;
        $totalPl = $realized + $unrealized;

        // Zirveyi guncelle
        $peak = $pos->tp_peak !== null ? (float) $pos->tp_peak : $totalPl;
        if ($totalPl > $peak) {
            $peak = $totalPl;
            $pos->tp_peak = $peak;
            $pos->save();
        }

        $threshold = ($pct / 100) * (float) $bot->budget;
        $drop = $peak - $totalPl;

        // Yalnizca POZITIF bir zirveden geri cekilmede ve acik pozisyon varken
        if ($peak > 0 && $pos->quantity > 0 && $drop >= $threshold) {
            $order = $this->sell($bot, $pos->quantity, 'trailing_tp', $price);
            if ($order) {
                if (in_array($bot->strategy, ['grid', 'grid_v2'], true)) {
                    $bot->gridLevels()->update(['status' => 'waiting_buy', 'quantity' => 0, 'buy_order_quote' => 0]);
                }

                // Zirveyi yeni toplam K/Z'ye (artik tamami gerceklesen) sifirla
                $pos->refresh();
                $pos->tp_peak = (float) $pos->realized_profit;
                $pos->save();

                if ($this->notifier->notifyTrades) {
                    $this->notifier->send(
                        "🟡 [Trade] {$bot->symbol} Trailing take-profit — pozisyon kapatıldı.\n".
                        'Zirve K/Z: '.kb_money($peak)." {$bot->quote_asset}, geri çekilme: ".kb_money($drop)." (eşik %{$pct}).\n".
                        'Bu kapanış: '.($order->realized_profit >= 0 ? '+' : '').kb_money($order->realized_profit)." {$bot->quote_asset}."
                    );
                }

                return 'Trailing take-profit: pozisyon kapatıldı (zirveden '.kb_money($drop).' geri çekildi).';
            }
        }

        return null;
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
            $pct = max(0.0001, (float) ($params['percent'] ?? 10) / 100);
            $pairs = $this->autoGridPairs($price, $pct, $levels, $params['anchor'] ?? 'symmetric');
        } elseif ($rangeMode === 'atr') {
            $price = $this->client->getLastPrice($bot->symbol, $bot->symbol_type ?? 1);
            if ($price <= 0) {
                return false;
            }
            $step = $this->atrStep($bot, $params);
            if ($step <= 0) {
                return false;
            }
            $pairs = $this->atrGridPairs($price, $step, $levels, $params['anchor'] ?? 'below');
        } else {
            $lower = (float) ($params['lower'] ?? 0);
            $upper = (float) ($params['upper'] ?? 0);
            if ($lower <= 0 || $upper <= $lower) {
                return false;
            }
            // Manuel: verilen aralik kademelere esit (dogrusal) bolunur.
            $step = ($upper - $lower) / $levels;
            $pairs = [];
            for ($i = 0; $i < $levels; $i++) {
                $buy = $lower + $step * $i;
                $pairs[] = [$buy, $buy + $step];
            }
        }

        // Sabit satis kari secildiyse her kademe alis*(1+%X) fiyatindan satar.
        $pairs = $this->applySellProfit($pairs, $params);

        if (empty($pairs)) {
            return false;
        }

        $this->persistGridLevels($bot, $pairs);

        return true;
    }

    /**
     * AUTO grid kademeleri: adim YUZDESI kademe basinadir.
     * - Alis -> Satis arasi tam %pct (sell = buy * (1+pct)).
     * - Ardisik alis fiyatlari arasi tam %pct (her alt kademe ust kademenin %pct altinda).
     * anchor=below   : tum merdiven guncel fiyatin altinda (en ust kademe fiyatin %pct altinda).
     * anchor=symmetric: kademeler fiyatin altinda ve ustunde dengeli dagilir.
     *
     * @return array<int, array{0: float, 1: float}> Artan sirada [buy, sell] ciftleri.
     */
    protected function autoGridPairs(float $price, float $pct, int $levels, string $anchor): array
    {
        // buy_i = price * (1-pct)^(zero - i)  ->  her kademe bir ust kademenin %pct altinda
        // zero: buy = price olacak (sanal) indeks. below'da tum kademeler altta kalir.
        $zero = $anchor === 'below' ? $levels : intdiv($levels, 2);
        $down = 1 - $pct;
        $up = 1 + $pct;

        $pairs = [];
        for ($i = 0; $i < $levels; $i++) {
            $buy = $price * ($down ** ($zero - $i));
            $pairs[] = [$buy, $buy * $up];
        }

        return $pairs;
    }

    /**
     * Sabit satis kari: sell_profit_pct > 0 ise her kademenin satis fiyatini
     * alis * (1 + %X) yapar (alim araligindan bagimsiz). 0 ise ciftler degismez.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     * @return array<int, array{0: float, 1: float}>
     */
    protected function applySellProfit(array $pairs, array $params): array
    {
        $sellPct = (float) ($params['sell_profit_pct'] ?? 0);
        if ($sellPct <= 0) {
            return $pairs;
        }
        $f = 1 + $sellPct / 100;

        return array_map(fn ($pr) => [$pr[0], $pr[0] * $f], $pairs);
    }

    /** ATR (volatilite) tabanli kademe adimini (fiyat birimi) hesaplar. 0 = hesaplanamadi. */
    protected function atrStep(TradeBot $bot, array $params): float
    {
        $interval = $params['atr_interval'] ?? '1h';
        $period = max(2, (int) ($params['atr_period'] ?? 14));
        $mult = max(0.1, (float) ($params['atr_mult'] ?? 1.0));

        $ohlc = $this->client->getOhlc($bot->symbol, $interval, $period + 60, $bot->symbol_type ?? 1);
        $atr = Indicators::atr($ohlc['highs'], $ohlc['lows'], $ohlc['closes'], $period);
        if (! $atr || $atr <= 0) {
            return 0.0;
        }

        return $atr * $mult;
    }

    /**
     * ATR adimina gore dogrusal (mutlak) kademeler. sell = buy + step.
     * anchor=below: tum merdiven fiyatin altinda; symmetric: fiyatin iki yaninda.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    protected function atrGridPairs(float $price, float $step, int $levels, string $anchor): array
    {
        if ($step <= 0) {
            return [];
        }
        $zero = $anchor === 'below' ? $levels : intdiv($levels, 2);

        $pairs = [];
        for ($i = 0; $i < $levels; $i++) {
            $buy = $price - $step * ($zero - $i);
            if ($buy <= 0) {
                continue;
            }
            $pairs[] = [$buy, $buy + $step];
        }

        return $pairs;
    }

    /** Verilen [buy, sell] ciftlerini grid kademesi olarak yazar (eskiler silinir). */
    protected function persistGridLevels(TradeBot $bot, array $pairs): void
    {
        $bot->gridLevels()->delete();
        foreach (array_values($pairs) as $i => [$buy, $sell]) {
            TradeGridLevel::create([
                'trade_bot_id' => $bot->id,
                'level_index' => $i,
                'buy_price' => $buy,
                'sell_price' => $sell,
                'status' => 'waiting_buy',
                'quantity' => 0,
                'buy_order_quote' => 0,
            ]);
        }
    }

    /**
     * Trailing: mevcut grid genisligini koruyarak kademeleri guncel fiyat
     * etrafina yeniden ortalar. (Yalnizca acik pozisyon yokken cagrilmali.)
     */
    public function recenterGrid(TradeBot $bot, float $price): bool
    {
        if ($price <= 0) {
            return false;
        }

        $params = $bot->params ?? [];

        // AUTO modda: kademe-basina %step korunarak guncel fiyata gore yeniden kur.
        if (($params['range_mode'] ?? 'manual') === 'auto') {
            $pct = max(0.0001, (float) ($params['percent'] ?? 10) / 100);
            $levels = max(2, (int) ($params['levels'] ?? 5));
            $this->persistGridLevels($bot, $this->applySellProfit($this->autoGridPairs($price, $pct, $levels, $params['anchor'] ?? 'symmetric'), $params));

            return true;
        }

        // ATR modda: volatiliteyi yeniden olcup guncel fiyata gore kur.
        if (($params['range_mode'] ?? 'manual') === 'atr') {
            return $this->buildGrid($bot);
        }

        // MANUEL modda: mevcut genisligi koruyarak kaydir.
        $levels = $bot->gridLevels()->orderBy('level_index')->get();
        if ($levels->isEmpty()) {
            return $this->buildGrid($bot);
        }

        $count = $levels->count();
        $low = (float) $levels->first()->buy_price;
        $high = (float) $levels->last()->sell_price;
        $width = $high - $low;
        if ($width <= 0) {
            return false;
        }

        $newLower = ($bot->param('anchor', 'symmetric') === 'below')
            ? max(0.0, $price - $width)
            : max(0.0, $price - $width / 2);
        $step = $width / $count;

        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $buy = $newLower + $step * $i;
            $pairs[] = [$buy, $buy + $step];
        }
        $this->persistGridLevels($bot, $this->applySellProfit($pairs, $params));

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
        $sideLabel = $order->side === 'BUY' ? 'ALIM' : 'SATIŞ';
        $title = $bot->name ?: $bot->symbol;
        $sub = $bot->name ? ($bot->symbol.' · '.$bot->strategyLabel()) : $bot->strategyLabel();

        $lines = [
            "{$emoji} {$sideLabel} · {$title} ({$modeLabel})",
            "{$sub} — ".kb_reason_label($order->reason),
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

    /**
     * Ust uste API/islem hatasi takibi. Esige (3 ardisik tur) ulasinca BIR kez
     * Telegram uyarisi gonderir; sonraki turlarda spam yapmaz.
     */
    protected function recordApiError(string $message): void
    {
        $key = "trade_api_err_{$this->user->id}";
        $streak = (int) AppSetting::get($key, 0) + 1;
        AppSetting::put($key, (string) $streak);

        if ($streak === 3 && $this->notifier->notifyErrors) {
            $this->notifier->send(
                "⚠️ Trade: API/işlem hataları üst üste 3 turdur tekrarlanıyor.\n".
                "Son hata: {$message}\n".
                'API anahtarı, ağ bağlantısı veya bakiye sorunlu olabilir.'
            );
        }
    }

    /** Hata serisi bittiyse bayragi sifirlar; esige ulasilmissa toparlama bilgisi yollar. */
    protected function recordApiRecovered(): void
    {
        $key = "trade_api_err_{$this->user->id}";
        $streak = (int) AppSetting::get($key, 0);
        if ($streak === 0) {
            return;
        }
        if ($streak >= 3 && $this->notifier->notifyErrors) {
            $this->notifier->send('✅ Trade: API/işlemler tekrar normal.');
        }
        AppSetting::forget($key);
    }
}
