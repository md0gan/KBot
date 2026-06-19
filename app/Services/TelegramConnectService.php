<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ortak (uygulama geneli) Telegram botu + kullanici bazli "tek tikla bagla" akisi.
 *
 * - Yonetici tek bir bot token'i girer (configureApp).
 * - Her kullanici "Bagla" der; tek kullanimlik bir kod uretilir ve
 *   https://t.me/<bot>?start=<kod> deep-link'i acilir.
 * - Kullanici Telegram'da "Basla"ya basinca bot /start <kod> mesaji alir.
 * - poll() getUpdates ile bu mesaji yakalar, kodu ilgili kullaniciyla eslestirir,
 *   o kullanicinin chat_id'sini kaydeder. Boylece bildirimler yalnizca o
 *   kullanicinin Telegram'ina gider.
 */
class TelegramConnectService
{
    protected const API = 'https://api.telegram.org/bot';

    public function appToken(): ?string
    {
        return AppSetting::get('telegram_app_bot_token');
    }

    public function appUsername(): ?string
    {
        return AppSetting::get('telegram_app_bot_username');
    }

    public function isConfigured(): bool
    {
        return filled($this->appToken());
    }

    /**
     * Yonetici tarafindan ortak bot token'ini dogrular (getMe) ve saklar.
     * Bot kullanici adini otomatik ceker; webhook varsa siler (polling icin).
     *
     * @return array{ok: bool, username?: string, error?: string}
     */
    public function configureApp(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Token bos olamaz.'];
        }

        try {
            $resp = Http::timeout(10)->get(self::API.$token.'/getMe');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Baglanti hatasi: '.$e->getMessage()];
        }

        if (! $resp->ok() || ! $resp->json('ok')) {
            return ['ok' => false, 'error' => $resp->json('description') ?: 'Token dogrulanamadi.'];
        }

        $username = (string) $resp->json('result.username');

        AppSetting::put('telegram_app_bot_token', $token);
        AppSetting::put('telegram_app_bot_username', $username);
        AppSetting::put('telegram_poll_offset', '0');

        // Anlik komut/baglama icin webhook kur.
        $webhook = $this->registerWebhook($token);

        return ['ok' => true, 'username' => $username, 'webhook' => $webhook];
    }

    /**
     * Telegram webhook'unu uygulamaya kaydeder (anlik guncellemeler).
     * APP_URL https olmali. setWebhook sonucunu doner.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public function registerWebhook(string $token): array
    {
        $secret = Str::random(40);

        try {
            $url = route('telegram.webhook', ['secret' => $secret]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Webhook URL üretilemedi: '.$e->getMessage()];
        }

        // Cloudflare/proxy arkasinda scheme http uretebilir; webhook icin https zorunlu.
        $url = preg_replace('#^http://#i', 'https://', $url);
        if (! preg_match('#^https://[^/]+\.[^/]+#', (string) $url)) {
            return ['ok' => false, 'error' => 'Webhook için geçerli alan adı gerekli (sunucuda APP_URL=https://alan-adınız). Üretilen: '.$url];
        }

        try {
            $resp = Http::timeout(10)->get(self::API.$token.'/setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => json_encode(['message']),
                'drop_pending_updates' => 'true',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Baglanti hatasi: '.$e->getMessage()];
        }

        if (! $resp->ok() || ! $resp->json('ok')) {
            return ['ok' => false, 'error' => $resp->json('description') ?: 'setWebhook başarısız.'];
        }

        AppSetting::put('telegram_webhook_secret', $secret);

        return ['ok' => true, 'url' => $url];
    }

    public function webhookActive(): bool
    {
        return filled(AppSetting::get('telegram_webhook_secret'));
    }

    public function webhookSecret(): ?string
    {
        return AppSetting::get('telegram_webhook_secret');
    }

    public function clearApp(): void
    {
        $token = $this->appToken();
        if ($token) {
            try {
                Http::timeout(10)->get(self::API.$token.'/deleteWebhook', ['drop_pending_updates' => 'false']);
            } catch (\Throwable $e) {
                // yoksay
            }
        }

        AppSetting::forget('telegram_app_bot_token');
        AppSetting::forget('telegram_app_bot_username');
        AppSetting::forget('telegram_poll_offset');
        AppSetting::forget('telegram_webhook_secret');
    }

    /**
     * Bir kullanici icin tek kullanimlik baglama kodu uretir ve deep-link doner.
     * Ortak bot ayarli degilse null doner.
     */
    public function startConnect(Setting $setting): ?string
    {
        $username = $this->appUsername();
        if (! $this->isConfigured() || blank($username)) {
            return null;
        }

        $code = Str::random(28);
        $setting->telegram_connect_token = $code;
        $setting->telegram_enabled = true; // baglamak isteyen bildirim de istiyordur
        $setting->save();

        return 'https://t.me/'.$username.'?start='.$code;
    }

    /** Kullanicinin baglantisini kaldirir (chat_id ve kod temizlenir). */
    public function disconnect(Setting $setting): void
    {
        $setting->telegram_chat_id = null;
        $setting->telegram_connect_token = null;
        $setting->telegram_connected_at = null;
        $setting->save();
    }

    /**
     * getUpdates ile bekleyen "/start <kod>" mesajlarini yakalar ve eslestirir.
     * Hem zamanlanmis komut hem de ayar sayfasindaki durum sorgusu bunu cagirir.
     *
     * @return int Eslenen (baglanan) kullanici sayisi
     */
    public function poll(): int
    {
        $token = $this->appToken();
        if (blank($token)) {
            return 0;
        }

        // Webhook aktifse getUpdates kullanilamaz (anlik webhook isler).
        if ($this->webhookActive()) {
            return 0;
        }

        $offset = (int) AppSetting::get('telegram_poll_offset', 0);

        $query = ['timeout' => 0, 'allowed_updates' => json_encode(['message'])];
        if ($offset > 0) {
            $query['offset'] = $offset;
        }

        try {
            $resp = Http::timeout(15)->get(self::API.$token.'/getUpdates', $query);
        } catch (\Throwable $e) {
            return 0;
        }

        if (! $resp->ok() || ! $resp->json('ok')) {
            return 0;
        }

        $updates = $resp->json('result') ?: [];
        $linked = 0;
        $maxId = $offset - 1;

        foreach ($updates as $u) {
            $updateId = (int) ($u['update_id'] ?? 0);
            if ($updateId > $maxId) {
                $maxId = $updateId;
            }
            $linked += $this->handleUpdate($u);
        }

        // Islenen update'leri onayla: bir sonraki offset = en buyuk update_id + 1.
        if ($maxId >= $offset && ! empty($updates)) {
            AppSetting::put('telegram_poll_offset', (string) ($maxId + 1));
        }

        return $linked;
    }

    /**
     * Tek bir Telegram update'ini isler (hem webhook hem polling kullanir).
     * "/start <kod>" -> hesap baglama; diger /komutlar -> handleCommand.
     *
     * @return int Bu update ile baglanan kullanici sayisi (0/1)
     */
    public function handleUpdate(array $u): int
    {
        $token = $this->appToken();
        if (blank($token)) {
            return 0;
        }

        $msg = $u['message'] ?? null;
        if (! $msg) {
            return 0;
        }

        $text = trim((string) ($msg['text'] ?? ''));
        $chatId = $msg['chat']['id'] ?? null;
        if ($chatId === null) {
            return 0;
        }

        // "/start <kod>" -> hesaba baglama; diger mesajlar -> komut.
        if (preg_match('#^/start\s+(\S+)#', $text, $m)) {
            $code = $m[1];
            $setting = Setting::query()->where('telegram_connect_token', $code)->first();

            if ($setting) {
                $setting->telegram_chat_id = (string) $chatId;
                $setting->telegram_connected_at = now();
                $setting->telegram_connect_token = null;
                $setting->telegram_enabled = true;
                $setting->save();

                $this->sendVia($token, $chatId, "✅ KBot hesabınıza bağlandınız. Bildirimler bu sohbete gelecek.\nKomutlar: /salter (otomatik işlemleri aç/kapat), /durum.");

                return 1;
            }

            $this->sendVia($token, $chatId, "⚠️ Bu bağlantı kodu geçersiz veya süresi dolmuş. Panelden tekrar \"Telegram'ı Bağla\" deyin.");

            return 0;
        }

        $this->handleCommand($token, $chatId, $text);

        return 0;
    }

    /**
     * Bagli bir sohbetten gelen komutu isler. "Salter" (/salter) tum otomatik
     * islemleri (yatirim + trade) ac/kapat yapar: Setting::bot_enabled toggle.
     * Bu bayrak hem TradingBot hem TradeEngine zamanlanmis kosullarini gecer.
     */
    protected function handleCommand(string $appToken, $chatId, string $text): void
    {
        $cmd = strtolower((string) strtok(ltrim($text), " \t\n"));
        if ($cmd === '' || $cmd[0] !== '/') {
            return; // komut degil; sessizce yoksay (spam onleme)
        }
        // "/salter@BotAdi" gibi son ekleri ayikla
        $cmd = explode('@', $cmd)[0];

        $setting = Setting::query()->where('telegram_chat_id', (string) $chatId)->first();
        if (! $setting) {
            $this->sendVia($appToken, $chatId, "Bu sohbet bir hesaba bağlı değil. Panelden \"Telegram'ı Bağla\" deyin.");

            return;
        }

        switch ($cmd) {
            case '/salter':
            case '/pause':
            case '/duraklat':
                $setting->bot_enabled = ! $setting->bot_enabled;
                $setting->save();
                $this->sendVia($appToken, $chatId, $setting->bot_enabled
                    ? "▶️ Otomatik işlemler AÇIK. Duraklatmak için /salter."
                    : "⏸️ Tüm otomatik işlemler DURAKLATILDI. Açmak için /salter.");
                break;

            case '/durum':
            case '/status':
                $this->sendVia($appToken, $chatId, $setting->bot_enabled
                    ? "Durum: ▶️ AÇIK — otomatik işlemler çalışıyor. /salter ile durdurabilirsiniz."
                    : "Durum: ⏸️ DURAKLATILDI — otomatik işlemler duruyor. /salter ile açabilirsiniz.");
                break;

            default:
                $this->sendVia($appToken, $chatId,
                    "Komutlar:\n/salter — tüm otomatik işlemleri aç/kapat (şalter)\n/durum — mevcut durumu göster");
        }
    }

    protected function sendVia(string $token, $chatId, string $text): void
    {
        try {
            Http::timeout(10)->asForm()->post(self::API.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            // bildirim gonderimi botu bozmaz
        }
    }
}
