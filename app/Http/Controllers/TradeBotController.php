<?php

namespace App\Http\Controllers;

use App\Models\TradeBot;
use App\Services\Trade\Backtest;
use App\Services\Trade\TradeEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TradeBotController extends Controller
{
    public function index(Request $request): View
    {
        $bots = $request->user()->tradeBots()->with('position')->latest()->get();

        $invested = (float) $bots->sum(fn ($b) => $b->position?->cost_basis ?? 0);
        $currentValue = (float) $bots->sum(fn ($b) => $b->position
            ? ($b->position->last_value ?? $b->position->cost_basis)
            : 0);
        $realized = (float) $bots->sum(fn ($b) => $b->position?->realized_profit ?? 0);
        $unrealized = $currentValue - $invested;

        $quote = $bots->pluck('quote_asset')->filter()->countBy()->sortDesc()->keys()->first()
            ?: ($request->user()->settings()->default_quote ?: 'TRY');

        $recentOrders = $request->user()->tradeOrders()->with('tradeBot')->latest()->limit(12)->get();

        return view('trade.index', compact('bots', 'invested', 'currentValue', 'unrealized', 'realized', 'quote', 'recentOrders'));
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
            'strategy' => $data['strategy'],
            'mode' => $data['mode'],
            'budget' => $data['budget'],
            'order_size' => ($data['order_size'] ?? 0) ?: $data['budget'],
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
            'strategy' => $data['strategy'],
            'mode' => $data['mode'],
            'budget' => $data['budget'],
            'order_size' => ($data['order_size'] ?? 0) ?: $data['budget'],
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

    /* ------------------------------------------------------------------ */

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
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
            'notes' => ['nullable', 'string', 'max:1000'],
            // grid
            'range_mode' => ['nullable', 'in:manual,auto'],
            'lower' => ['nullable', 'numeric', 'min:0'],
            'upper' => ['nullable', 'numeric', 'min:0'],
            'percent' => ['nullable', 'numeric', 'min:0.1', 'max:90'],
            'levels' => ['nullable', 'integer', 'min:2', 'max:100'],
            'trailing' => ['nullable', 'boolean'],
            'anchor' => ['nullable', 'in:symmetric,below'],
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

        return $params;
    }

    protected function authorizeBot(Request $request, TradeBot $tradeBot): void
    {
        abort_unless($tradeBot->user_id === $request->user()->id, 403);
    }
}
