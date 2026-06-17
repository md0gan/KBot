<?php

namespace App\Services;

use App\Models\BotLog;
use App\Models\Coin;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Strateji motoru.
 *
 * DCA (duzenli alim): her periyotta sabit kote tutar kadar (orn. 10 USDT)
 * MARKET alim yapar; pozisyonun "sermayesini" (cost_basis) ve miktarini buyutur.
 *
 * Kar-al: pozisyon degeri >= carpan x sermaye olunca, karin tamamini (leave_capital)
 * ya da bir kismini (fixed_ratio) satip USDT'ye cevirir; geriye sadece sermaye kalir.
 * Satistan sonra sermaye = kalan miktarin guncel degeri olarak yeniden esitlenir,
 * boylece bir sonraki kar-al ayni carpanla tekrar tetiklenir.
 */
class TradingBot
{
    public User $user;
    public Setting $setting;
    protected BinanceTrClient $client;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->setting = $user->settings();
        $this->client = BinanceTrClient::fromSetting($this->setting);
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
     | Toplu calistirma (komutlardan cagrilir)
     * ==================================================================== */

    /** Vadesi gelen tum coinlerde alim yapar. @return array<int,string> sonuc satirlari */
    public function runDueBuys(): array
    {
        $results = [];

        if (! $this->setting->bot_enabled) {
            return ['Bot bu kullanici icin kapali (Ayarlar).'];
        }

        foreach ($this->user->coins()->due()->get() as $coin) {
            try {
                $trade = $this->buy($coin, 'dca_buy');
                $results[] = "[ALIM] {$coin->symbol}: {$trade->quantity} @ {$trade->price} = {$trade->quote_amount} {$coin->quote_asset} ({$trade->mode})";
            } catch (\Throwable $e) {
                $this->log('error', 'dca_buy_failed', "{$coin->symbol} alim hatasi: ".$e->getMessage(), $coin);
                $results[] = "[HATA] {$coin->symbol}: ".$e->getMessage();
            }
        }

        return $results ?: ['Vadesi gelen coin yok.'];
    }

    /** Tum acik pozisyonlari degerlendirir (kar-al). @return array<int,string> */
    public function evaluateAll(): array
    {
        $results = [];

        if (! $this->setting->bot_enabled) {
            return ['Bot bu kullanici icin kapali (Ayarlar).'];
        }

        foreach ($this->user->coins()->enabled()->with('position')->get() as $coin) {
            try {
                $trade = $this->evaluate($coin);
                if ($trade) {
                    $results[] = "[KAR-AL] {$coin->symbol}: {$trade->quantity} satildi -> {$trade->quote_amount} {$coin->quote_asset} kar ({$trade->mode})";
                }
            } catch (\Throwable $e) {
                $this->log('error', 'evaluate_failed', "{$coin->symbol} degerlendirme hatasi: ".$e->getMessage(), $coin);
                $results[] = "[HATA] {$coin->symbol}: ".$e->getMessage();
            }
        }

        return $results ?: ['Kar-al kosulu olusan coin yok.'];
    }

    /* ======================================================================
     | Alim (DCA / manuel)
     * ==================================================================== */

    public function buy(Coin $coin, string $kind = 'dca_buy', ?float $amount = null): Trade
    {
        $amount = $amount ?? (float) $coin->buy_amount;
        if ($amount <= 0) {
            throw new BinanceTrException('Alim tutari 0 veya negatif olamaz.');
        }

        $mode = $coin->effectiveMode($this->globalMode());

        if ($mode === 'live') {
            $fill = $this->client->marketBuyQuote($coin->symbol, $amount);
            $qty = (float) $fill['quantity'];
            $quote = (float) $fill['quote_amount'];
            $price = (float) $fill['price'];
            $status = $fill['status'];
            $orderId = $fill['order_id'] ?? null;
            $raw = $fill['raw'] ?? null;

            if ($qty <= 0) {
                throw new BinanceTrException("Emir dolmadi (status: {$status}).");
            }
        } else {
            // Simulasyon: guncel fiyattan al
            $price = $this->client->getLastPrice($coin->symbol, $coin->symbol_type ?? 1);
            if ($price <= 0) {
                throw new BinanceTrException('Gecerli fiyat alinamadi.');
            }
            $quote = $amount;
            $qty = $amount / $price;
            $status = 'SIMULATED';
            $orderId = null;
            $raw = null;
        }

        // Pozisyon kaydini kilit oncesi olustur (kilit altinda INSERT yarisini onler)
        $coin->position()->firstOrCreate([]);

        return DB::transaction(function () use ($coin, $kind, $mode, $qty, $quote, $price, $status, $orderId, $raw) {
            $pos = $coin->position()->lockForUpdate()->first();

            $pos->quantity += $qty;
            $pos->cost_basis += $quote;
            $pos->avg_price = $pos->quantity > 0 ? $pos->cost_basis / $pos->quantity : 0;
            $pos->last_price = $price;
            $pos->last_value = $pos->quantity * $price;
            $pos->last_valued_at = now();
            $pos->save();

            $trade = Trade::create([
                'user_id' => $coin->user_id,
                'coin_id' => $coin->id,
                'symbol' => $coin->symbol,
                'side' => 'BUY',
                'kind' => $kind,
                'mode' => $mode,
                'quantity' => $qty,
                'price' => $price,
                'quote_amount' => $quote,
                'status' => $status,
                'order_id' => $orderId,
                'reason' => $kind === 'dca_buy' ? 'Zamanlanmis duzenli alim' : 'Manuel alim',
                'raw' => $raw,
                'executed_at' => now(),
            ]);

            // Sonraki alim zamanini planla
            $coin->last_buy_at = now();
            $coin->next_buy_at = $coin->nextBuyAfter(now());
            $coin->save();

            $this->log('info', 'buy', "{$coin->symbol} alindi: {$qty} @ {$price}", $coin, [
                'mode' => $mode, 'quote' => $quote,
            ]);

            return $trade;
        });
    }

    /* ======================================================================
     | Kar-al degerlendirmesi
     * ==================================================================== */

    public function evaluate(Coin $coin): ?Trade
    {
        $pos = $coin->position()->firstOrCreate([]);

        // Degerleme icin guncel fiyat
        $price = $this->client->getLastPrice($coin->symbol, $coin->symbol_type ?? 1);
        if ($price <= 0) {
            return null;
        }

        // Degerleme anlik gorunusu guncelle
        $pos->last_price = $price;
        $pos->last_value = $pos->quantity * $price;
        $pos->last_valued_at = now();

        if (! $pos->hasHoldings()) {
            $pos->save();

            return null;
        }

        $value = $pos->quantity * $price;
        $ratio = $value / $pos->cost_basis;

        // Carpana ulasilmadiysa sadece degerlemeyi kaydet
        if ($ratio < (float) $coin->profit_multiplier) {
            $pos->save();

            return null;
        }

        // --- Tetiklendi: satilacak deger ---
        $excess = $value - $pos->cost_basis; // sermaye ustu kar
        $fraction = $coin->take_profit_strategy === 'fixed_ratio'
            ? max(0.0, min(1.0, (float) $coin->sell_ratio))
            : 1.0; // leave_capital: karin tamamini al

        $sellValue = $excess * $fraction;
        if ($sellValue <= 0) {
            $pos->save();

            return null;
        }

        $sellQty = $this->roundQty($coin, min($sellValue / $price, $pos->quantity));

        // Borsa minimumlari
        if ($sellQty <= 0) {
            $this->log('warning', 'profit_take_skip', "{$coin->symbol}: hesaplanan satis miktari cok kucuk.", $coin);
            $pos->save();

            return null;
        }
        if ($coin->min_qty && $sellQty < (float) $coin->min_qty) {
            $this->log('warning', 'profit_take_skip', "{$coin->symbol}: min_qty altinda ({$sellQty} < {$coin->min_qty}).", $coin);
            $pos->save();

            return null;
        }
        if ($coin->min_notional && ($sellQty * $price) < (float) $coin->min_notional) {
            $this->log('warning', 'profit_take_skip', "{$coin->symbol}: min_notional altinda.", $coin);
            $pos->save();

            return null;
        }

        $mode = $coin->effectiveMode($this->globalMode());

        if ($mode === 'live') {
            $fill = $this->client->marketSellQuantity($coin->symbol, $sellQty);
            $soldQty = (float) $fill['quantity'];
            $proceeds = (float) $fill['quote_amount'];
            $execPrice = (float) $fill['price'] ?: $price;
            $status = $fill['status'];
            $orderId = $fill['order_id'] ?? null;
            $raw = $fill['raw'] ?? null;

            if ($soldQty <= 0) {
                throw new BinanceTrException("Satis emri dolmadi (status: {$status}).");
            }
        } else {
            $soldQty = $sellQty;
            $execPrice = $price;
            $proceeds = $sellQty * $price;
            $status = 'SIMULATED';
            $orderId = null;
            $raw = null;
        }

        return DB::transaction(function () use ($coin, $pos, $soldQty, $proceeds, $execPrice, $status, $orderId, $raw, $mode) {
            $locked = $coin->position()->lockForUpdate()->first();

            $locked->quantity = max(0, $locked->quantity - $soldQty);
            // Kar-al daima yalnizca "sermaye ustu" kismi satar; sermaye coinde kalir.
            // Bu yuzden elde edilen tutarin TAMAMI USDT'ye cevrilen kardir (realized_profit += proceeds).
            // Kalan miktarin guncel degeri yeni sermaye olur => carpan 1'e doner, acik kar 0'lanir.
            // (Manuel sell() ise sermayeye girebildigi icin ortalama maliyetle proceeds - costOfSold yazar.)
            $locked->cost_basis = $locked->quantity * $execPrice;
            $locked->avg_price = $execPrice;
            $locked->realized_profit += $proceeds;
            $locked->last_price = $execPrice;
            $locked->last_value = $locked->quantity * $execPrice;
            $locked->last_valued_at = now();
            $locked->profit_takes_count += 1;
            $locked->save();

            $trade = Trade::create([
                'user_id' => $coin->user_id,
                'coin_id' => $coin->id,
                'symbol' => $coin->symbol,
                'side' => 'SELL',
                'kind' => 'profit_take',
                'mode' => $mode,
                'quantity' => $soldQty,
                'price' => $execPrice,
                'quote_amount' => $proceeds,
                'realized_profit' => $proceeds,
                'status' => $status,
                'order_id' => $orderId,
                'reason' => 'Kar-al: carpana ulasildi, kar USDT yapildi',
                'raw' => $raw,
                'executed_at' => now(),
            ]);

            $this->log('info', 'profit_take', "{$coin->symbol} kar-al: {$soldQty} satildi, +{$proceeds} {$coin->quote_asset}", $coin, [
                'mode' => $mode,
            ]);

            return $trade;
        });
    }

    /* ======================================================================
     | Manuel satis (panelden tum/kismi sat)
     * ==================================================================== */

    public function sell(Coin $coin, float $quantity): Trade
    {
        $pos = $coin->position()->firstOrCreate([]);
        $quantity = $this->roundQty($coin, min($quantity, $pos->quantity));
        if ($quantity <= 0) {
            throw new BinanceTrException('Satilacak miktar gecersiz.');
        }

        $mode = $coin->effectiveMode($this->globalMode());

        if ($mode === 'live') {
            $fill = $this->client->marketSellQuantity($coin->symbol, $quantity);
            $soldQty = (float) $fill['quantity'];
            $proceeds = (float) $fill['quote_amount'];
            $execPrice = (float) $fill['price'];
            $status = $fill['status'];
            $orderId = $fill['order_id'] ?? null;
            $raw = $fill['raw'] ?? null;
            if ($soldQty <= 0) {
                throw new BinanceTrException("Satis emri dolmadi (status: {$status}).");
            }
        } else {
            $execPrice = $this->client->getLastPrice($coin->symbol, $coin->symbol_type ?? 1);
            $soldQty = $quantity;
            $proceeds = $quantity * $execPrice;
            $status = 'SIMULATED';
            $orderId = null;
            $raw = null;
        }

        return DB::transaction(function () use ($coin, $soldQty, $proceeds, $execPrice, $status, $orderId, $raw, $mode) {
            $locked = $coin->position()->lockForUpdate()->first();
            $soldShare = $locked->quantity > 0 ? min(1.0, $soldQty / $locked->quantity) : 0;
            $costOfSold = $locked->cost_basis * $soldShare;

            $locked->quantity = max(0, $locked->quantity - $soldQty);
            $locked->cost_basis = max(0, $locked->cost_basis - $costOfSold);
            $locked->avg_price = $locked->quantity > 0 ? $locked->cost_basis / $locked->quantity : 0;
            $locked->realized_profit += ($proceeds - $costOfSold);
            $locked->last_price = $execPrice;
            $locked->last_value = $locked->quantity * $execPrice;
            $locked->last_valued_at = now();
            $locked->save();

            $trade = Trade::create([
                'user_id' => $coin->user_id,
                'coin_id' => $coin->id,
                'symbol' => $coin->symbol,
                'side' => 'SELL',
                'kind' => 'manual_sell',
                'mode' => $mode,
                'quantity' => $soldQty,
                'price' => $execPrice,
                'quote_amount' => $proceeds,
                'realized_profit' => $proceeds - $costOfSold,
                'status' => $status,
                'order_id' => $orderId,
                'reason' => 'Manuel satis',
                'raw' => $raw,
                'executed_at' => now(),
            ]);

            $this->log('info', 'manual_sell', "{$coin->symbol} manuel satis: {$soldQty}", $coin, ['mode' => $mode]);

            return $trade;
        });
    }

    /* ======================================================================
     | Degerleme (sadece fiyat guncelle, islem yapma)
     * ==================================================================== */

    public function refreshValuation(Coin $coin): ?float
    {
        try {
            $price = $this->client->getLastPrice($coin->symbol, $coin->symbol_type ?? 1);
        } catch (\Throwable $e) {
            return null;
        }
        if ($price <= 0) {
            return null;
        }

        $pos = $coin->position()->firstOrCreate([]);
        $pos->last_price = $price;
        $pos->last_value = $pos->quantity * $price;
        $pos->last_valued_at = now();
        $pos->save();

        return $price;
    }

    /* ======================================================================
     | Sembol senkronu (filtreler + dogrulama)
     * ==================================================================== */

    public function syncSymbols(): int
    {
        $symbols = $this->client->getSymbols();
        if (empty($symbols)) {
            return 0;
        }

        $map = [];
        foreach ($symbols as $s) {
            $map[strtoupper((string) data_get($s, 'symbol'))] = $s;
        }

        $updated = 0;
        foreach ($this->user->coins as $coin) {
            $meta = $map[strtoupper($coin->symbol)] ?? null;
            if (! $meta) {
                continue;
            }

            $filters = collect((array) data_get($meta, 'filters', []));
            $lot = $filters->firstWhere('filterType', 'LOT_SIZE');
            $notional = $filters->firstWhere('filterType', 'NOTIONAL')
                ?? $filters->firstWhere('filterType', 'MIN_NOTIONAL');

            $coin->symbol_type = (int) data_get($meta, 'type', $coin->symbol_type);
            $coin->base_precision = (int) data_get($meta, 'basePrecision', $coin->base_precision);
            $coin->quote_precision = (int) data_get($meta, 'quotePrecision', $coin->quote_precision);
            if ($lot) {
                $coin->min_qty = (float) data_get($lot, 'minQty');
                $coin->step_size = (float) data_get($lot, 'stepSize');
            }
            if ($notional) {
                $coin->min_notional = (float) data_get($notional, 'minNotional');
            }
            $coin->last_synced_at = now();
            $coin->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Sadece bir sembolun borsada gecerli olup olmadigini dogrular ve
     * meta verisini dondurur (coin eklerken kullanilir).
     */
    public function lookupSymbol(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        foreach ($this->client->getSymbols() as $s) {
            if (strtoupper((string) data_get($s, 'symbol')) === $symbol) {
                return $s;
            }
        }

        return null;
    }

    /* ======================================================================
     | Altyapi
     * ==================================================================== */

    protected function roundQty(Coin $coin, float $qty): float
    {
        $step = (float) ($coin->step_size ?? 0);
        if ($step > 0) {
            return floor($qty / $step) * $step;
        }
        if ($coin->base_precision !== null) {
            $f = 10 ** $coin->base_precision;

            return floor($qty * $f) / $f;
        }

        return $qty;
    }

    protected function log(string $level, string $event, string $message, ?Coin $coin = null, array $context = []): void
    {
        BotLog::write($level, $event, $message, $context, $this->user->id, $coin?->id);
    }
}
