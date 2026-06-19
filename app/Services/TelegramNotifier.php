<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API uzerinden bilgilendirme mesaji gonderir (salt bildirim).
 * Hata olursa sessizce false doner; botun isleyisini asla bozmaz.
 */
class TelegramNotifier
{
    protected bool $enabled;
    protected ?string $token;
    protected ?string $chatId;

    public bool $notifyTrades;
    public bool $notifyErrors;
    public bool $notifyBalance;

    public function __construct(Setting $setting)
    {
        $this->enabled = (bool) $setting->telegram_enabled;
        // Kullanici kendi botunu girmediyse uygulama geneli ortak bot kullanilir.
        $this->token = $setting->effectiveTelegramToken();
        $this->chatId = $setting->telegram_chat_id;
        $this->notifyTrades = (bool) $setting->tg_notify_trades;
        $this->notifyErrors = (bool) $setting->tg_notify_errors;
        $this->notifyBalance = (bool) $setting->tg_notify_balance;
    }

    public static function fromSetting(Setting $setting): self
    {
        return new self($setting);
    }

    public function isReady(): bool
    {
        return $this->enabled && filled($this->token) && filled($this->chatId);
    }

    /**
     * Mesaj gonderir. Telegram hatasi/baglanti sorunu olursa sessizce false doner.
     */
    public function send(string $text): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        try {
            $resp = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$this->token}/sendMessage",
                [
                    'chat_id' => $this->chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]
            );

            return $resp->ok();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kimlik bilgileri verilip verilmedigine bakmaksizin test mesaji denemesi.
     * Test butonu icin: gecici olarak ayarlari kullanir.
     */
    public function sendTest(): bool
    {
        // Test ederken "enabled" kapaliysa bile dene (kullanici test ediyor)
        $this->enabled = true;

        return $this->send("✅ KBot Telegram bağlantı testi başarılı. Bildirimler bu sohbete gelecek.");
    }
}
