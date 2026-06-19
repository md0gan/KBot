<?php

namespace App\Console\Commands;

use App\Services\TelegramConnectService;
use Illuminate\Console\Command;

/**
 * Ortak Telegram botu icin webhook'u (yeniden) kurar. Saklanan token kullanilir;
 * token'i tekrar girmeye gerek kalmadan deploy sonrasi calistirilabilir.
 *
 * NOT: CLI'da URL, .env'deki APP_URL'den uretilir; APP_URL=https://alan-adınız olmali.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'bot:telegram-webhook';

    protected $description = 'Ortak Telegram botu icin webhook kurar (saklanan token ile)';

    public function handle(TelegramConnectService $service): int
    {
        $token = $service->appToken();
        if (! $token) {
            $this->error('Ortak bot token ayarli degil. Once: Ayarlar > Uygulama Telegram Botu.');

            return self::FAILURE;
        }

        $res = $service->registerWebhook($token);
        if ($res['ok'] ?? false) {
            $this->info('Webhook kuruldu: '.($res['url'] ?? ''));

            return self::SUCCESS;
        }

        $this->error('Webhook kurulamadi: '.($res['error'] ?? 'bilinmeyen hata'));

        return self::FAILURE;
    }
}
