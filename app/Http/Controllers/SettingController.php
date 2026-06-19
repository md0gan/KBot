<?php

namespace App\Http\Controllers;

use App\Services\BinanceTrClient;
use App\Services\TelegramConnectService;
use App\Services\TelegramNotifier;
use App\Services\TradingBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(Request $request, TelegramConnectService $connect): View
    {
        $setting = $request->user()->settings();
        $tgAppConfigured = $connect->isConfigured();
        $tgAppUsername = $connect->appUsername();

        return view('settings.edit', compact('setting', 'tgAppConfigured', 'tgAppUsername'));
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
            'telegram_enabled' => ['nullable', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'tg_notify_trades' => ['nullable', 'boolean'],
            'tg_notify_errors' => ['nullable', 'boolean'],
            'tg_notify_balance' => ['nullable', 'boolean'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        // API anahtarlari sadece doldurulduysa guncellenir
        if (filled($data['api_key'] ?? null)) {
            $setting->api_key = trim($data['api_key']);
        }
        if (filled($data['api_secret'] ?? null)) {
            $setting->api_secret = trim($data['api_secret']);
        }

        // Telegram bot token sadece doldurulduysa guncellenir
        if (filled($data['telegram_bot_token'] ?? null)) {
            $setting->telegram_bot_token = trim($data['telegram_bot_token']);
        }

        $setting->base_url = $data['base_url'] ?: null;
        $setting->market_base_url = $data['market_base_url'] ?: null;
        $setting->recv_window = $data['recv_window'];
        $setting->default_quote = strtoupper($data['default_quote']);
        $setting->trading_mode = $data['trading_mode'];
        $setting->bot_enabled = $request->boolean('bot_enabled');
        $setting->telegram_enabled = $request->boolean('telegram_enabled');
        // chat_id yalnizca form bu alani gonderdiyse guncellenir; ortak-bot ile
        // baglanan kullanicinin yakalanmis chat_id'sini yanlislikla silmeyiz.
        if ($request->has('telegram_chat_id')) {
            $setting->telegram_chat_id = $data['telegram_chat_id'] ?: null;
        }
        $setting->tg_notify_trades = $request->boolean('tg_notify_trades');
        $setting->tg_notify_errors = $request->boolean('tg_notify_errors');
        $setting->tg_notify_balance = $request->boolean('tg_notify_balance');
        $setting->low_balance_threshold = $data['low_balance_threshold'] ?: null;
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

    public function testTelegram(Request $request): RedirectResponse
    {
        $setting = $request->user()->settings();

        if (! filled($setting->effectiveTelegramToken()) || ! filled($setting->telegram_chat_id)) {
            return back()->with('error', 'Önce Telegram\'ı bağlayın (veya kendi bot token + chat ID\'nizi girip kaydedin).');
        }

        $ok = TelegramNotifier::fromSetting($setting)->sendTest();

        return $ok
            ? back()->with('status', 'Telegram test mesajı gönderildi. Sohbeti kontrol edin.')
            : back()->with('error', 'Telegram mesajı gönderilemedi. Bağlantıyı (veya token/chat ID) kontrol edin.');
    }

    /**
     * Kullanici icin tek kullanimlik baglama kodu uretir; ortak bot deep-link'ini
     * session'a koyar (sayfa "Telegram'da Aç" butonunu gosterir).
     */
    public function telegramConnect(Request $request, TelegramConnectService $connect): RedirectResponse
    {
        $url = $connect->startConnect($request->user()->settings());

        if (! $url) {
            return back()->with('error', 'Ortak Telegram botu henüz ayarlanmadı. Yöneticinin uygulama botunu tanımlaması gerekir.');
        }

        return back()->with('tg_connect_url', $url);
    }

    /** Kullanicinin Telegram baglantisini kaldirir. */
    public function telegramDisconnect(Request $request, TelegramConnectService $connect): RedirectResponse
    {
        $connect->disconnect($request->user()->settings());

        return back()->with('status', 'Telegram bağlantısı kaldırıldı.');
    }

    /** Ayar sayfasinin canli durum sorgusu: bekleyen baglamayi yakalamayi dener. */
    public function telegramStatus(Request $request, TelegramConnectService $connect): JsonResponse
    {
        $setting = $request->user()->settings();

        if (filled($setting->telegram_connect_token)) {
            $connect->poll();          // cron'u beklemeden hemen yakalamayi dene
            $setting->refresh();
        }

        return response()->json([
            'connected' => $setting->isTelegramConnected(),
            'chat_id' => $setting->telegram_chat_id,
        ]);
    }

    /** Yonetici: uygulama geneli ortak Telegram botunu tanimlar/kaldirir. */
    public function telegramApp(Request $request, TelegramConnectService $connect): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($request->boolean('clear_app')) {
            $connect->clearApp();

            return back()->with('status', 'Uygulama Telegram botu kaldırıldı.');
        }

        $data = $request->validate([
            'app_bot_token' => ['required', 'string', 'max:255'],
        ]);

        $res = $connect->configureApp($data['app_bot_token']);

        return $res['ok']
            ? back()->with('status', 'Uygulama botu ayarlandı: @'.$res['username'])
            : back()->with('error', 'Bot ayarlanamadı: '.$res['error']);
    }

    public function toggleMode(Request $request): RedirectResponse
    {
        $setting = $request->user()->settings();
        $oldMode = $setting->trading_mode;
        $setting->trading_mode = $oldMode === 'live' ? 'simulation' : 'live';
        $setting->save();

        if ($oldMode === 'simulation' && $setting->trading_mode === 'live') {
            $res = (new TradingBot($request->user()))->clearSimulationData();

            return back()->with('status',
                "Genel mod: CANLI. Simülasyon verileri temizlendi "
                ."({$res['trades']} işlem, {$res['positions']} pozisyon).");
        }

        return back()->with('status', 'Genel işlem modu: '.strtoupper($setting->trading_mode));
    }
}
