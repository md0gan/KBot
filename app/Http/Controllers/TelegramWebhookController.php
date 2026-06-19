<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\TelegramConnectService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram webhook ucu: ortak bota gelen guncellemeleri (mesaj/komut) ANLIK isler.
 * Guvenlik: URL'deki gizli anahtar + Telegram'in secret-token header'i dogrulanir.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, string $secret, TelegramConnectService $service): Response
    {
        $expected = AppSetting::get('telegram_webhook_secret');
        if (! $expected || ! hash_equals((string) $expected, (string) $secret)) {
            abort(404);
        }

        // Telegram, setWebhook'ta verdigimiz secret_token'i bu header'da gonderir.
        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($header !== null && ! hash_equals((string) $expected, (string) $header)) {
            abort(403);
        }

        $update = $request->all();
        if (is_array($update) && ! empty($update)) {
            try {
                $service->handleUpdate($update);
            } catch (\Throwable $e) {
                // Telegram'in tekrar tekrar denememesi icin yine de 200 donuyoruz.
            }
        }

        return response('OK', 200);
    }
}
