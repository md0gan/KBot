<?php

namespace App\Http\Controllers;

use App\Models\TradeBot;
use App\Services\Trade\Backtest;
use App\Services\Trade\TradeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradeBotController extends Controller
{
    public function index(Request $request): View
    {
        $tagFilter = trim((string) $request->query('tag', ''));
        $botsQuery = $request->user()->tradeBots()->with(['position', 'gridLevels']);
        if ($tagFilter !== '') {
            $botsQuery->where('tag', $tagFilter);
        }
        $bots = $botsQuery->latest()->get();

        $tags = $request->user()->tradeBots()
            ->whereNotNull('tag')->where('tag', '!=', '')
            ->distinct()->orderBy('tag')->pluck('tag');

        $invested = (float) $bots->sum(fn ($b) => $b->position?->cost_basis ?? 0);
        $currentValue = (float) $bots->sum(fn ($b) => $b->position
            ? ($b->position->last_value ?? $b->position->cost_basis)
            : 0);
        $realized = (float) $bots->sum(fn ($b) => $b->position?->realized_profit ?? 0);
        $unrealized = $currentValue - $invested;

        $setting = $request->user()->settings();
        $quote = $bots->pluck('quote_asset')->filter()->countBy()->sortDesc()->keys()->first()
            ?: ($setting->default_quote ?: 'TRY');
        $globalMode = $setting->trading_mode ?? 'simulation';

        $recentOrders = $request->user()->tradeOrders()->with('tradeBot')->latest()->limit(12)->get();

        return view('trade.index', compact('bots', 'invested', 'currentValue', 'unrealized', 'realized', 'quote', 'globalMode', 'recentOrders', 'tags', 'tagFilter'));
    }

    public function create(): View
    {
        return view('trade.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $base = strtoupper($data['base_asset']);
        $quote = strtoupper($data['quote_asset']);
        $symbol = $base.'_'.$quote;

        $bot = new TradeBot([
            'name' => $data['name'] ?? null,
            'tag' => $data['tag'] ?? null,
            'strategy' => $data['strategy'],
            'mode' => $data['mode'],
            'budget' => $data['budget'],
            'order_size' => $data['order_size'] ?? 0,
            'max_buy_price' => $data['max_buy_price'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $bot->user_id = $request->user()->id;
        $bot->base_asset = $base;
        $bot->quote_asset = $quote;
        $bot->symbol = $symbol;
        $bot->params = $this->buildParams($data);
        $bot->enabled = $request->boolean('enabled');
        $bot->save();

        // Sembol filtrelerini ve (grid ise) kademeleri kur
        $engine = new TradeEngine($request->user());
        $warning = null;
        try {
            if (! $engine->syncSymbol($bot)) {
                $warning = "Uyarı: {$symbol} borsada bulunamadı, sembolü kontrol edin.";
            }
            $bot->refresh();
            if ($bot->strategy === 'grid' && ! $engine->buildGrid($bot)) {
                $warning = 'Uyarı: Grid aralığı geçersiz; "Grid\'i yeniden kur" ile düzeltin.';
            }
        } catch (\Throwable $e) {
            $warning = 'Sembol/grid kurulumu şimdi yapılamadı: '.$e->getMessage();
        }

        $redirect = redirect()->route('trade.show', $bot)->with('status', "Trade botu oluşturuldu: {$symbol}");

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    public function show(Request $request, TradeBot $tradeBot): View
    {
        $this->authorizeBot($request, $tradeBot);
        $tradeBot->load('position');
        $levels = $tradeBot->strategy === 'grid' ? $tradeBot->gridLevels()->get() : collect();
        $orders = $tradeBot->orders()->paginate(20);

        return view('trade.show', compact('tradeBot', 'levels', 'orders'));
    }

    public function edit(Request $request, TradeBot $tradeBot): View
    {
        $this->authorizeBot($request, $tradeBot);

        return view('trade.edit', ['tradeBot' => $tradeBot]);
    }

    public function update(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        $data = $this->validateData($request);

        $base = strtoupper($data['base_asset']);
        $quote = strtoupper($data['quote_asset']);

        $tradeBot->fill([
            'name' => $data['name'] ?? null,
            'tag' => $data['tag'] ?? null,
            'strategy' => $data['strategy'],
            'mode' => $data['mode'],
            'budget' => $data['budget'],
            'order_size' => $data['order_size'] ?? 0,
            'max_buy_price' => $data['max_buy_price'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $tradeBot->base_asset = $base;
        $tradeBot->quote_asset = $quote;
        $tradeBot->symbol = $base.'_'.$quote;
        $tradeBot->params = $this->buildParams($data);
        $tradeBot->enabled = $request->boolean('enabled');
        $tradeBot->save();

        return redirect()->route('trade.show', $tradeBot)
            ->with('status', 'Trade botu güncellendi.'.($tradeBot->strategy === 'grid' ? ' Grid aralığını değiştirdiyseniz "Grid\'i yeniden kur" deyin.' : ''));
    }

    public function destroy(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        $symbol = $tradeBot->symbol;
        $tradeBot->delete();

        return redirect()->route('trade.index')->with('status', "Trade botu silindi: {$symbol}");
    }

    public function toggle(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        $tradeBot->enabled = ! $tradeBot->enabled;
        $tradeBot->save();

        return back()->with('status', $tradeBot->enabled ? 'Trade botu aktif edildi.' : 'Trade botu durduruldu.');
    }

    public function runNow(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        try {
            $lines = (new TradeEngine($request->user()))->run($tradeBot);

            return back()->with('status', 'Çalıştırıldı: '.implode(' | ', array_slice($lines, 0, 6)));
        } catch (\Throwable $e) {
            return back()->with('error', 'Çalıştırma hatası: '.$e->getMessage());
        }
    }

    public function sellAll(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        $qty = (float) ($tradeBot->position->quantity ?? 0);
        if ($qty <= 0) {
            return back()->with('error', 'Satılacak pozisyon yok.');
        }
        try {
            $order = (new TradeEngine($request->user()))->sell($tradeBot, $qty, 'manual_sell');
            // Grid ise kademeleri sifirla (tutulanlar elle satildi)
            if ($order && $tradeBot->strategy === 'grid') {
                $tradeBot->gridLevels()->update(['status' => 'waiting_buy', 'quantity' => 0, 'buy_order_quote' => 0]);
            }

            return back()->with('status', $order ? "Tüm pozisyon satıldı: {$order->quantity} {$tradeBot->base_asset}" : 'Satış yapılamadı.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Satış hatası: '.$e->getMessage());
        }
    }

    public function rebuildGrid(Request $request, TradeBot $tradeBot): RedirectResponse
    {
        $this->authorizeBot($request, $tradeBot);
        if ($tradeBot->strategy !== 'grid') {
            return back()->with('error', 'Bu bot grid stratejisi değil.');
        }
        try {
            $ok = (new TradeEngine($request->user()))->buildGrid($tradeBot);

            return back()->with($ok ? 'status' : 'error', $ok ? 'Grid kademeleri yeniden kuruldu.' : 'Grid aralığı geçersiz.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Grid kurulum hatası: '.$e->getMessage());
        }
    }

    public function orders(Request $request): View
    {
        $query = $request->user()->tradeOrders()->with('tradeBot')->latest();
        if ($request->filled('bot')) {
            $query->where('trade_bot_id', $request->integer('bot'));
        }
        $orders = $query->paginate(30)->withQueryString();
        $bots = $request->user()->tradeBots()->orderBy('symbol')->get();

        return view('trade.orders', compact('orders', 'bots'));
    }

    /** Trade islem gecmisini CSV (UTF-8 BOM, Excel uyumlu) olarak indirir. Bot filtresine uyar. */
    public function exportOrders(Request $request): StreamedResponse
    {
        $query = $request->user()->tradeOrders()->latest();
        if ($request->filled('bot')) {
            $query->where('trade_bot_id', $request->integer('bot'));
        }

        $filename = 'trade-islemleri-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel'de Turkce karakterler icin UTF-8 BOM
            fputcsv($out, ['Tarih', 'Sembol', 'Strateji', 'Yon', 'Mod', 'Miktar', 'Fiyat', 'Tutar', 'K/Z', 'Sebep', 'Durum']);

            $query->chunk(500, function ($orders) use ($out) {
                foreach ($orders as $o) {
                    fputcsv($out, [
                        $o->executed_at?->format('Y-m-d H:i:s'),
                        $o->symbol,
                        $o->strategy,
                        $o->side,
                        $o->mode,
                        rtrim(rtrim(number_format((float) $o->quantity, 12, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format((float) $o->price, 12, '.', ''), '0'), '.'),
                        number_format((float) $o->quote_amount, 2, '.', ''),
                        $o->realized_profit !== null ? number_format((float) $o->realized_profit, 2, '.', '') : '',
                        $o->reason,
                        $o->status,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Performans paneli: trade emirlerinden türetilen K/Z, kazanma oranı, kıyas ve kümülatif eğri. */
    public function performance(Request $request): View
    {
        $user = $request->user();
        $quote = $user->settings()->default_quote ?: 'TRY';

        $sells = $user->tradeOrders()
            ->where('side', 'SELL')
            ->orderBy('executed_at')
            ->get(['trade_bot_id', 'symbol', 'strategy', 'realized_profit', 'executed_at']);

        $totalRealized = (float) $sells->sum('realized_profit');
        $totalTrades = $sells->count();
        $wins = $sells->where('realized_profit', '>', 0)->count();
        $winRate = $totalTrades > 0 ? $wins / $totalTrades * 100 : 0.0;
        $totalFees = (float) $user->tradeOrders()->sum('fee');

        $openPl = (float) $user->tradeBots()->with('position')->get()->sum(function ($b) {
            $p = $b->position;
            if (! $p) {
                return 0.0;
            }

            return (float) (($p->last_value ?? $p->cost_basis) - $p->cost_basis);
        });

        $agg = function ($g) {
            $n = $g->count();
            $w = $g->where('realized_profit', '>', 0)->count();

            return ['trades' => $n, 'realized' => (float) $g->sum('realized_profit'), 'win_rate' => $n ? $w / $n * 100 : 0.0];
        };
        $byStrategy = $sells->groupBy('strategy')->map($agg)->sortByDesc('realized');
        $bySymbol = $sells->groupBy('symbol')->map($agg)->sortByDesc('realized');

        // Kümülatif gerçekleşen K/Z (güne göre)
        $labels = [];
        $cum = [];
        $running = 0.0;
        foreach ($sells->groupBy(fn ($o) => optional($o->executed_at)->format('Y-m-d'))->sortKeys() as $day => $g) {
            if (! $day) {
                continue;
            }
            $running += (float) $g->sum('realized_profit');
            $labels[] = $day;
            $cum[] = round($running, 2);
        }

        return view('trade.performance', compact(
            'totalRealized', 'totalTrades', 'winRate', 'openPl', 'totalFees',
            'byStrategy', 'bySymbol', 'labels', 'cum', 'quote'
        ));
    }

    public function backtest(Request $request, TradeBot $tradeBot): View
    {
        $this->authorizeBot($request, $tradeBot);

        $interval = (string) $request->input('interval', $tradeBot->param('interval', '15m'));
        $bars = max(50, min(1000, (int) $request->input('bars', 500)));
        $fee = max(0.0, min(5.0, (float) $request->input('fee', 0.1)));    // % komisyon
        $slip = max(0.0, min(5.0, (float) $request->input('slip', 0.05))); // % kayma

        $result = null;
        $error = null;

        if ($request->filled('run')) {
            try {
                $closes = (new TradeEngine($request->user()))->client()
                    ->getCloses($tradeBot->symbol, $interval, $bars, $tradeBot->symbol_type ?? 1);

                if (count($closes) < 30) {
                    $error = 'Yeterli geçmiş veri çekilemedi (sembol / zaman dilimi / borsa erişimi?).';
                } else {
                    $result = Backtest::run(
                        $tradeBot->strategy,
                        $tradeBot->params ?? [],
                        $closes,
                        (float) $tradeBot->budget,
                        (float) $tradeBot->order_size,
                        $fee / 100,
                        $slip / 100,
                    );
                    if (isset($result['error'])) {
                        $error = $result['error'];
                        $result = null;
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('trade.backtest', compact('tradeBot', 'interval', 'bars', 'fee', 'slip', 'result', 'error'));
    }

    /**
     * Parametre optimizasyonu: stratejiye gore bir parametre izgarasi taranir,
     * her kombinasyon ayni gecmis veri uzerinde backtest edilir ve Toplam K/Z'ye
     * gore siralanir. Botun diger ayarlari sabit kalir; SONUC OTOMATIK UYGULANMAZ.
     */
    public function optimize(Request $request, TradeBot $tradeBot): View
    {
        $this->authorizeBot($request, $tradeBot);

        $interval = (string) $request->input('interval', $tradeBot->param('interval', '1h'));
        $bars = max(80, min(1000, (int) $request->input('bars', 500)));
        $fee = max(0.0, min(5.0, (float) $request->input('fee', 0.1)));
        $slip = max(0.0, min(5.0, (float) $request->input('slip', 0.05)));

        $results = [];
        $error = null;
        $tested = 0;

        if ($request->filled('run')) {
            try {
                $closes = (new TradeEngine($request->user()))->client()
                    ->getCloses($tradeBot->symbol, $interval, $bars, $tradeBot->symbol_type ?? 1);

                if (count($closes) < 50) {
                    $error = 'Yeterli geçmiş veri çekilemedi (sembol / zaman dilimi / borsa erişimi?).';
                } else {
                    $base = $tradeBot->params ?? [];
                    $budget = (float) $tradeBot->budget;
                    $orderSize = (float) $tradeBot->order_size;

                    foreach ($this->optimizeCombos($tradeBot->strategy, $base) as $combo) {
                        $r = Backtest::run($tradeBot->strategy, array_merge($base, $combo), $closes, $budget, $orderSize, $fee / 100, $slip / 100);
                        if (isset($r['error'])) {
                            continue;
                        }
                        $tested++;
                        $results[] = ['params' => $combo, 'm' => $r];
                    }

                    usort($results, fn ($a, $b) => $b['m']['total_pl'] <=> $a['m']['total_pl']);
                    $results = array_slice($results, 0, 20);

                    if (empty($results)) {
                        $error = 'Bu strateji için taranacak kombinasyon bulunamadı.';
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('trade.optimize', compact('tradeBot', 'interval', 'bars', 'fee', 'slip', 'results', 'error', 'tested'));
    }

    /**
     * Stratejiye gore taranacak parametre kombinasyonlari (her biri base ile birlestirilir).
     *
     * @return array<int, array<string, int|float>>
     */
    protected function optimizeCombos(string $strategy, array $base): array
    {
        $combos = [];

        switch ($strategy) {
            case 'grid':
                $rangeMode = $base['range_mode'] ?? 'manual';
                $levelsSet = [5, 8, 12];
                $sellSet = [0, 5, 10];
                if ($rangeMode === 'auto') {
                    foreach ([2, 3, 5, 8] as $pct) {
                        foreach ($levelsSet as $lv) {
                            foreach ($sellSet as $sp) {
                                $combos[] = ['percent' => $pct, 'levels' => $lv, 'sell_profit_pct' => $sp];
                            }
                        }
                    }
                } elseif ($rangeMode === 'atr') {
                    foreach ([0.5, 1.0, 1.5, 2.0] as $m) {
                        foreach ($levelsSet as $lv) {
                            foreach ($sellSet as $sp) {
                                $combos[] = ['atr_mult' => $m, 'levels' => $lv, 'sell_profit_pct' => $sp];
                            }
                        }
                    }
                } else {
                    foreach ($levelsSet as $lv) {
                        foreach ($sellSet as $sp) {
                            $combos[] = ['levels' => $lv, 'sell_profit_pct' => $sp];
                        }
                    }
                }
                break;

            case 'rsi':
                foreach ([7, 14, 21] as $p) {
                    foreach ([20, 25, 30] as $os) {
                        foreach ([70, 75, 80] as $ob) {
                            $combos[] = ['period' => $p, 'oversold' => $os, 'overbought' => $ob];
                        }
                    }
                }
                break;

            case 'ma_cross':
                foreach ([5, 9, 12, 20] as $s) {
                    foreach ([21, 30, 50, 100] as $l) {
                        if ($s < $l) {
                            $combos[] = ['short' => $s, 'long' => $l];
                        }
                    }
                }
                break;

            case 'macd':
                foreach ([8, 12] as $f) {
                    foreach ([21, 26] as $sl) {
                        foreach ([7, 9] as $sg) {
                            if ($f < $sl) {
                                $combos[] = ['fast' => $f, 'slow' => $sl, 'signal' => $sg];
                            }
                        }
                    }
                }
                break;

            case 'bollinger':
                foreach ([14, 20, 30] as $p) {
                    foreach ([1.5, 2.0, 2.5] as $k) {
                        $combos[] = ['period' => $p, 'k' => $k];
                    }
                }
                break;
        }

        return $combos;
    }

    /* ------------------------------------------------------------------ */

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'tag' => ['nullable', 'string', 'max:40'],
            'base_asset' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
            'quote_asset' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'strategy' => ['required', 'in:grid,rsi,ma_cross,macd,bollinger'],
            'mode' => ['required', 'in:inherit,simulation,live'],
            'budget' => ['required', 'numeric', 'min:0.00000001'],
            'order_size' => ['nullable', 'numeric', 'min:0'],
            'max_buy_price' => ['nullable', 'numeric', 'min:0'],
            'enabled' => ['nullable', 'boolean'],
            'compounding' => ['nullable', 'boolean'],
            'max_loss_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trail_tp_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // grid
            'range_mode' => ['nullable', 'in:manual,auto,atr'],
            'lower' => ['nullable', 'numeric', 'min:0'],
            'upper' => ['nullable', 'numeric', 'min:0'],
            'percent' => ['nullable', 'numeric', 'min:0.1', 'max:90'],
            'levels' => ['nullable', 'integer', 'min:2', 'max:100'],
            'trailing' => ['nullable', 'boolean'],
            'anchor' => ['nullable', 'in:symmetric,below'],
            'atr_interval' => ['nullable', 'string', 'max:8'],
            'atr_period' => ['nullable', 'integer', 'min:2', 'max:100'],
            'atr_mult' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'sell_profit_pct' => ['nullable', 'numeric', 'min:0', 'max:200'],
            // rsi / ma / macd / bollinger ortak
            'interval' => ['nullable', 'string', 'max:8'],
            'period' => ['nullable', 'integer', 'min:2', 'max:200'],
            'oversold' => ['nullable', 'numeric', 'min:1', 'max:99'],
            'overbought' => ['nullable', 'numeric', 'min:1', 'max:99'],
            'short' => ['nullable', 'integer', 'min:1', 'max:400'],
            'long' => ['nullable', 'integer', 'min:2', 'max:800'],
            'ma_type' => ['nullable', 'in:sma,ema'],
            'fast' => ['nullable', 'integer', 'min:1', 'max:200'],
            'slow' => ['nullable', 'integer', 'min:2', 'max:400'],
            'signal' => ['nullable', 'integer', 'min:1', 'max:200'],
            'k' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            // ek filtreler (macd/bollinger)
            'trend_ma' => ['nullable', 'integer', 'min:0', 'max:400'],
            'require_above_zero' => ['nullable', 'boolean'],
            'confirm_rsi' => ['nullable', 'boolean'],
            // ust zaman dilimi (HTF) trend filtresi — indikator stratejileri
            'htf_interval' => ['nullable', 'string', 'max:8'],
            'htf_ma' => ['nullable', 'integer', 'min:0', 'max:400'],
        ]);
    }

    protected function buildParams(array $d): array
    {
        $params = match ($d['strategy']) {
            'grid' => [
                'range_mode' => $d['range_mode'] ?? 'manual',
                'lower' => (float) ($d['lower'] ?? 0),
                'upper' => (float) ($d['upper'] ?? 0),
                'percent' => (float) ($d['percent'] ?? 10),
                'levels' => (int) ($d['levels'] ?? 5),
                'trailing' => (bool) ($d['trailing'] ?? false),
                'anchor' => in_array($d['anchor'] ?? '', ['symmetric', 'below'], true) ? $d['anchor'] : 'symmetric',
                'atr_interval' => $d['atr_interval'] ?? '1h',
                'atr_period' => (int) ($d['atr_period'] ?? 14),
                'atr_mult' => (float) ($d['atr_mult'] ?? 1),
                'sell_profit_pct' => (float) ($d['sell_profit_pct'] ?? 0),
            ],
            'rsi' => [
                'interval' => $d['interval'] ?? '15m',
                'period' => (int) ($d['period'] ?? 14),
                'oversold' => (float) ($d['oversold'] ?? 30),
                'overbought' => (float) ($d['overbought'] ?? 70),
            ],
            'ma_cross' => [
                'interval' => $d['interval'] ?? '15m',
                'short' => (int) ($d['short'] ?? 9),
                'long' => (int) ($d['long'] ?? 21),
                'ma_type' => $d['ma_type'] ?? 'ema',
            ],
            'macd' => [
                'interval' => $d['interval'] ?? '15m',
                'fast' => (int) ($d['fast'] ?? 12),
                'slow' => (int) ($d['slow'] ?? 26),
                'signal' => (int) ($d['signal'] ?? 9),
                'trend_ma' => (int) ($d['trend_ma'] ?? 0),
                'require_above_zero' => (bool) ($d['require_above_zero'] ?? false),
            ],
            'bollinger' => [
                'interval' => $d['interval'] ?? '15m',
                'period' => (int) ($d['period'] ?? 20),
                'k' => (float) ($d['k'] ?? 2),
                'trend_ma' => (int) ($d['trend_ma'] ?? 0),
                'confirm_rsi' => (bool) ($d['confirm_rsi'] ?? false),
            ],
            default => [],
        };

        // Tum stratejilere ortak: kar biriktirme + zarar durdurma
        $params['compounding'] = (bool) ($d['compounding'] ?? false);
        $params['max_loss_pct'] = (float) ($d['max_loss_pct'] ?? 0);
        $params['trail_tp_pct'] = (float) ($d['trail_tp_pct'] ?? 0);
        // Ust zaman dilimi trend filtresi (yalnizca indikator stratejilerinde etkilidir)
        $params['htf_interval'] = $d['htf_interval'] ?? '4h';
        $params['htf_ma'] = (int) ($d['htf_ma'] ?? 0);

        return $params;
    }

    /** Fiyat grafigi icin mum (kline) verisi + grid kademeleri (JSON). */
    public function candles(Request $request, TradeBot $tradeBot): JsonResponse
    {
        $this->authorizeBot($request, $tradeBot);

        $interval = (string) $request->query('interval', '1h');
        if (! in_array($interval, ['1m', '5m', '15m', '30m', '1h', '4h', '1d'], true)) {
            $interval = '1h';
        }

        $points = [];
        try {
            $klines = (new TradeEngine($request->user()))
                ->client()
                ->getKlines($tradeBot->symbol, $interval, 120, $tradeBot->symbol_type ?? 1);

            foreach ($klines as $k) {
                if (is_array($k) && isset($k[0], $k[4])) {
                    // Standart Binance dizisi: [openTime, open, high, low, close, volume, ...]
                    $points[] = [
                        't' => (int) $k[0],
                        'o' => (float) ($k[1] ?? $k[4]),
                        'h' => (float) ($k[2] ?? $k[4]),
                        'l' => (float) ($k[3] ?? $k[4]),
                        'c' => (float) $k[4],
                        'v' => (float) ($k[5] ?? 0),
                    ];
                } elseif (is_array($k)) {
                    $t = $k['openTime'] ?? $k['time'] ?? $k['t'] ?? null;
                    $c = $k['close'] ?? $k['c'] ?? null;
                    if ($t !== null && $c !== null) {
                        $points[] = [
                            't' => (int) $t,
                            'o' => (float) ($k['open'] ?? $c),
                            'h' => (float) ($k['high'] ?? $c),
                            'l' => (float) ($k['low'] ?? $c),
                            'c' => (float) $c,
                            'v' => (float) ($k['volume'] ?? $k['vol'] ?? 0),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $points = [];
        }

        $grid = [];
        if ($tradeBot->strategy === 'grid') {
            foreach ($tradeBot->gridLevels()->orderBy('level_index')->get() as $lv) {
                $grid[] = [
                    'index' => $lv->level_index,
                    'buy' => (float) $lv->buy_price,
                    'sell' => (float) $lv->sell_price,
                    'status' => $lv->status,
                ];
            }
        }

        return response()->json([
            'symbol' => $tradeBot->symbol,
            'interval' => $interval,
            'points' => $points,
            'grid' => $grid,
        ]);
    }

    protected function authorizeBot(Request $request, TradeBot $tradeBot): void
    {
        abort_unless($tradeBot->user_id === $request->user()->id, 403);
    }
}
