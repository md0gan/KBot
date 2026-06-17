<?php

namespace App\Http\Controllers;

use App\Services\BinanceTrClient;
use App\Services\TradingBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(Request $request): View
    {
        $setting = $request->user()->settings();

        return view('settings.edit', compact('setting'));
    }

    public function update(Request $request): RedirectResponse
    {
        $setting = $request->user()->settings();
        $oldMode = $setting->trading_mode;

        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'market_base_url' => ['nullable', 'url', 'max:255'],
            'recv_window' => ['required', 'integer', 'min:1000', 'max:60000'],
            'default_quote' => ['required', 'string', 'max:16'],
            'trading_mode' => ['required', 'in:simulation,live'],
            'bot_enabled' => ['nullable', 'boolean'],
        ]);

        // API anahtarlari sadece doldurulduysa guncellenir
        if (filled($data['api_key'] ?? null)) {
            $setting->api_key = trim($data['api_key']);
        }
        if (filled($data['api_secret'] ?? null)) {
            $setting->api_secret = trim($data['api_secret']);
        }

        $setting->base_url = $data['base_url'] ?: null;
        $setting->market_base_url = $data['market_base_url'] ?: null;
        $setting->recv_window = $data['recv_window'];
        $setting->default_quote = strtoupper($data['default_quote']);
        $setting->trading_mode = $data['trading_mode'];
        $setting->bot_enabled = $request->boolean('bot_enabled');
        $setting->save();

        // Simulasyondan canliya gecildiyse simulasyon verilerini temizle
        if ($oldMode === 'simulation' && $setting->trading_mode === 'live') {
            $res = (new TradingBot($request->user()))->clearSimulationData();

            return redirect()->route('settings.edit')->with('status',
                "Ayarlar kaydedildi. CANLI moda geçildi; simülasyon verileri temizlendi "
                ."({$res['trades']} işlem silindi, {$res['positions']} pozisyon sıfırlandı).");
        }

        return redirect()->route('settings.edit')->with('status', 'Ayarlar kaydedildi.');
    }

    public function test(Request $request): RedirectResponse
    {
        $setting = $request->user()->settings();

        if (! $setting->hasApiCredentials()) {
            return back()->with('error', 'Once API anahtari ve secret girip kaydedin.');
        }

        try {
            $client = BinanceTrClient::fromSetting($setting);
            $res = $client->testConnection();

            $setting->api_verified_at = now();
            $setting->api_status = $res['can_trade'] ? 'OK (islem izni var)' : 'OK (islem izni YOK)';
            $setting->save();

            return back()->with('status', "Baglanti basarili. Varlik sayisi: {$res['assets']}, islem izni: ".($res['can_trade'] ? 'evet' : 'hayir'));
        } catch (\Throwable $e) {
            $setting->api_verified_at = null;
            $setting->api_status = 'HATA: '.$e->getMessage();
            $setting->save();

            return back()->with('error', 'Baglanti hatasi: '.$e->getMessage());
        }
    }

    public function toggleMode(Request $request): RedirectResponse
    {
        $setting = $request->user()->settings();
        $setting->trading_mode = $setting->trading_mode === 'live' ? 'simulation' : 'live';
        $setting->save();

        return back()->with('status', 'Genel islem modu: '.strtoupper($setting->trading_mode));
    }
}
