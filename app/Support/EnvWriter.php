<?php

namespace App\Support;

class EnvWriter
{
    /**
     * .env dosyasindaki anahtarlari gunceller (yoksa ekler).
     *
     * @param  array<string,string|int|null>  $values
     */
    public static function write(array $values): void
    {
        $path = base_path('.env');

        if (! file_exists($path) && file_exists(base_path('.env.example'))) {
            copy(base_path('.env.example'), $path);
        }

        $content = file_exists($path) ? (file_get_contents($path) ?: '') : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.self::format((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $content)) {
                // preg_replace_callback: parola icindeki $ vb. karakterler bozulmaz
                $content = preg_replace_callback($pattern, fn () => $line, $content, 1);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        file_put_contents($path, $content);
    }

    protected static function format(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Bosluk, #, tirnak veya ters bolu varsa cift tirnakla cevrele
        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
