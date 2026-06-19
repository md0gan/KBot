<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Uygulama geneli anahtar/deger ayar deposu (tek satir = bir ayar).
 * Ornek: ortak Telegram botu token'i, bot kullanici adi, getUpdates offset.
 *
 * SECRET_KEYS icindeki anahtarlarin degeri veritabaninda APP_KEY ile sifrelenir.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Sifrelenerek saklanacak anahtarlar. */
    public const SECRET_KEYS = ['telegram_app_bot_token'];

    public static function get(string $key, $default = null)
    {
        try {
            $row = static::query()->where('key', $key)->first();
        } catch (\Throwable $e) {
            // Tablo henuz olusmadiysa (migrate oncesi) calismayi bozma.
            return $default;
        }

        if (! $row || $row->value === null) {
            return $default;
        }

        $value = $row->value;
        if (in_array($key, self::SECRET_KEYS, true)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Throwable $e) {
                return $default;
            }
        }

        return $value;
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value !== null && in_array($key, self::SECRET_KEYS, true)) {
            $value = Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function forget(string $key): void
    {
        try {
            static::query()->where('key', $key)->delete();
        } catch (\Throwable $e) {
            // yoksay
        }
    }
}
