<?php

namespace App\Http\Controllers;

use App\Models\Coin;
use App\Services\TradingBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function buyNow(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);

        try {
            $trade = (new TradingBot($request->user()))->buy($coin, 'manual_buy');

            if (! $trade) {
                return back()->with('error', 'Alım yapılamadı.');
            }

            return back()->with('status', "Alim yapildi: {$trade->quantity} {$coin->base_asset} @ {$trade->price} ({$trade->mode})");
        } catch (\Throwable $e) {
            return back()->with('error', 'Alim hatasi: '.$e->getMessage());
        }
    }

    public function evaluateNow(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);

        try {
            $trade = (new TradingBot($request->user()))->evaluate($coin);

            return $trade
                ? back()->with('status', "Kar-al tetiklendi: +{$trade->quote_amount} {$coin->quote_asset} ({$trade->mode})")
                : back()->with('status', 'Kar-al kosulu henuz olusmadi (degerleme guncellendi).');
        } catch (\Throwable $e) {
            return back()->with('error', 'Degerlendirme hatasi: '.$e->getMessage());
        }
    }

    public function sell(Request $request, Coin $coin): RedirectResponse
    {
        $this->authorizeCoin($request, $coin);

        $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'all' => ['nullable', 'boolean'],
        ]);

        $qty = $request->boolean('all')
            ? (float) ($coin->position->quantity ?? 0)
            : (float) $request->input('quantity', 0);

        if ($qty <= 0) {
            return back()->with('error', 'Satilacak miktar gecersiz.');
        }

        try {
            $trade = (new TradingBot($request->user()))->sell($coin, $qty);

            return back()->with('status', "Satis yapildi: {$trade->quantity} {$coin->base_asset} -> {$trade->quote_amount} {$coin->quote_asset}");
        } catch (\Throwable $e) {
            return back()->with('error', 'Satis hatasi: '.$e->getMessage());
        }
    }

    public function runAll(Request $request): RedirectResponse
    {
        try {
            $bot = new TradingBot($request->user());
            $lines = array_merge($bot->runDueBuys(), $bot->evaluateAll());

            return back()->with('status', 'Bot calistirildi: '.implode(' | ', array_slice($lines, 0, 8)));
        } catch (\Throwable $e) {
            return back()->with('error', 'Bot calistirma hatasi: '.$e->getMessage());
        }
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $n = (new TradingBot($request->user()))->syncSymbols();

            return back()->with('status', "Sembol filtreleri guncellendi ({$n} coin).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Senkron hatasi: '.$e->getMessage());
        }
    }

    public function refresh(Request $request): RedirectResponse
    {
        $bot = new TradingBot($request->user());
        $ok = 0;
        $fail = 0;
        foreach ($request->user()->coins as $coin) {
            $bot->refreshValuation($coin) !== null ? $ok++ : $fail++;
        }

        return back()->with('status', "Fiyatlar yenilendi: {$ok} basarili".($fail ? ", {$fail} basarisiz" : '').'.');
    }

    protected function authorizeCoin(Request $request, Coin $coin): void
    {
        abort_unless($coin->user_id === $request->user()->id, 403);
    }
}
