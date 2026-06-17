<?php

namespace App\Http\Controllers;

use App\Models\Coin;
use App\Services\TradingBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoinController extends Controller
{
    public function index(Request $request): View
    {
        $coins = $request->user()->coins()->with('position')->orderBy('base_asset')->get();

        return view('coins.index', compact('coins'));
    }

    public function create(): View
    {
        return view('coins.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $symbol = $data['base_asset'].'_'.$data['quote_asset'];

        if ($request->user()->coins()->where('symbol', $symbol)->exists()) {
            return back()->withInput()->withErrors(['base_asset' => "Bu sembol zaten ekli: {$symbol}"]);
        }

        $coin = new Coin($data);
        $coin->symbol = $symbol;
        $coin->user_id = $request->user()->id;
        if ($coin->enabled) {
            $coin->next_buy_at = now();
        }
        $coin->save();

        // Sembol filtrelerini dogrula/doldur (borsa public endpoint)
        $warning = $this->tryFillFilters($request, $coin);

        $redirect = redirect()->route('coins.show', $coin)->with('status', "Coin eklendi: {$symbol}");

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    public function show(Request $request, Coin $coin): View
    {
        $this->authorizeCoin($request, $coin);
        $coin->load('position');
        $trades = $coin->trades()->paginate(20);
        $logs = $coin->logs()->limit(30)->get();

        return view('coins.show', compact('coin', 'trades', 'logs'));
    }

    public function edit(Request $request, Coin $coin): View
    {
        $this->authorizeCoin($request, $coin);

        return view('coins.edit', compact('coin'));
    }

    public function update(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);
        $data = $this->validateData($request);

        $symbol = $data['base_asset'].'_'.$data['quote_asset'];
        if ($symbol !== $coin->symbol
            && $request->user()->coins()->where('symbol', $symbol)->whereKeyNot($coin->id)->exists()) {
            return back()->withInput()->withErrors(['base_asset' => "Bu sembol zaten ekli: {$symbol}"]);
        }

        $coin->fill($data);
        $coin->symbol = $symbol;
        $coin->save();

        $this->tryFillFilters($request, $coin);

        return redirect()->route('coins.show', $coin)->with('status', 'Coin guncellendi.');
    }

    public function destroy(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);
        $symbol = $coin->symbol;
        $coin->delete();

        return redirect()->route('coins.index')->with('status', "Coin silindi: {$symbol}");
    }

    public function toggle(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);
        $coin->enabled = ! $coin->enabled;
        if ($coin->enabled && ! $coin->next_buy_at) {
            $coin->next_buy_at = now();
        }
        $coin->save();

        return back()->with('status', $coin->enabled ? 'Coin aktif edildi.' : 'Coin durduruldu.');
    }

    /* ------------------------------------------------------------------ */

    protected function validateData(Request $request): array
    {
        $validated = $request->validate([
            'base_asset' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
            'quote_asset' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'mode' => ['required', 'in:inherit,simulation,live'],
            'enabled' => ['nullable', 'boolean'],
            'buy_amount' => ['required', 'numeric', 'min:0.00000001'],
            'interval' => ['required', 'in:hourly,daily,weekly,monthly'],
            'buy_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'profit_multiplier' => ['required', 'numeric', 'min:1.01'],
            'take_profit_strategy' => ['required', 'in:leave_capital,fixed_ratio'],
            'sell_ratio' => ['nullable', 'numeric', 'min:0.01', 'max:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['base_asset'] = strtoupper($validated['base_asset']);
        $validated['quote_asset'] = strtoupper($validated['quote_asset']);
        $validated['enabled'] = $request->boolean('enabled');
        $validated['buy_hour'] = $validated['buy_hour'] ?? 9;
        $validated['sell_ratio'] = $validated['sell_ratio'] ?? 0.5;

        return $validated;
    }

    /** Borsadan sembolu dogrular ve filtreleri doldurur. Hata mesaji dondurur (varsa). */
    protected function tryFillFilters(Request $request, Coin $coin): ?string
    {
        try {
            $bot = new TradingBot($request->user());
            $meta = $bot->lookupSymbol($coin->symbol);
            if (! $meta) {
                return "Uyari: {$coin->symbol} borsada bulunamadi. Sembolu kontrol edin (orn. BTC + USDT).";
            }
            $bot->syncSymbols();

            return null;
        } catch (\Throwable $e) {
            return 'Sembol filtreleri su an dogrulanamadi (API/baglanti): '.$e->getMessage();
        }
    }

    protected function authorizeCoin(Request $request, Coin $coin): void
    {
        abort_unless($coin->user_id === $request->user()->id, 403);
    }
}
