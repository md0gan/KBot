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
        // Yeni token girildiyse eski offset imleci anlamsiz; sifirla.
        AppSetting::put('telegram_poll_offset', '0');

        // getUpdates'in calismasi icin webhook olmamali.
        try {
            Http::timeout(10)->get(self::API.$token.'/deleteWebhook', ['drop_pending_updates' => 'false']);
        } catch (\Throwable $e) {
            // yoksay
        }

        return ['ok' => true, 'username' => $username];
    }

    public function clearApp(): void
    {
        AppSetting::forget('telegram_app_bot_token');
        AppSetting::forget('telegram_app_bot_username');
        AppSetting::forget('telegram_poll_offset');
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

            $msg = $u['message'] ?? null;
            if (! $msg) {
                continue;
            }

            $text = trim((string) ($msg['text'] ?? ''));
            $chatId = $msg['chat']['id'] ?? null;
            if ($chatId === null) {
                continue;
            }

            // "/start <kod>" formatini yakala.
            if (! preg_match('#^/start\s+(\S+)#', $text, $m)) {
                continue;
            }

            $code = $m[1];
            $setting = Setting::query()->where('telegram_connect_token', $code)->first();

            if ($setting) {
                $setting->telegram_chat_id = (string) $chatId;
                $setting->telegram_connected_at = now();
                $setting->telegram_connect_token = null;
                $setting->telegram_enabled = true;
                $setting->save();
                $linked++;

                $this->sendVia($token, $chatId, "✅ KBot hesabınıza bağlandınız. Bildirimler bu sohbete gelecek.");
            } else {
                $this->sendVia($token, $chatId, "⚠️ Bu bağlantı kodu geçersiz veya süresi dolmuş. Panelden tekrar \"Telegram'ı Bağla\" deyin.");
            }
        }

        // Islenen update'leri onayla: bir sonraki offset = en buyuk update_id + 1.
        if ($maxId >= $offset && ! empty($updates)) {
            AppSetting::put('telegram_poll_offset', (string) ($maxId + 1));
        }

        return $linked;
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
