<?php

namespace App\Console\Commands;

use App\Services\TelegramConnectService;
use Illuminate\Console\Command;

/**
 * Ortak Telegram botuna gelen "/start <kod>" mesajlarini yakalayip
 * kullanici hesaplarina baglar. Zamanlayicidan her dakika calisir.
 */
class PollTelegram extends Command
{
    protected $signature = 'bot:telegram-poll';

    protected $description = 'Ortak Telegram botuna gelen baglama mesajlarini isler (getUpdates)';

    public function handle(TelegramConnectService $service): int
    {
        if (! $service->isConfigured()) {
            // Ortak bot ayarli degil; sessizce gec.
            return self::SUCCESS;
        }

        $linked = $service->poll();

        if ($linked > 0) {
            $this->info("{$linked} kullanici Telegram'a baglandi.");
        }

        return self::SUCCESS;
    }
}
