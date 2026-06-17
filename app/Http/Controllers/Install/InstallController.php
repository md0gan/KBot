<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EnvWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;

class InstallController extends Controller
{
    /** 1. Adim: gereksinim kontrolu */
    public function index(): View
    {
        $php = [
            'PHP >= 8.2 (mevcut: '.PHP_VERSION.')' => version_compare(PHP_VERSION, '8.2.0', '>='),
        ];

        $ext = [];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'bcmath', 'curl', 'fileinfo'] as $e) {
            $ext[$e] = extension_loaded($e);
        }

        $writable = [
            'storage/' => is_writable(storage_path()),
            'bootstrap/cache/' => is_writable(base_path('bootstrap/cache')),
            '.env' => file_exists(base_path('.env')) ? is_writable(base_path('.env')) : is_writable(base_path()),
        ];

        $appKey = ! empty(config('app.key'));

        $canProceed = ! in_array(false, $php, true)
            && ! in_array(false, $ext, true)
            && ! in_array(false, $writable, true)
            && $appKey;

        return view('install.requirements', [
            'step' => 1,
            'php' => $php,
            'ext' => $ext,
            'writable' => $writable,
            'appKey' => $appKey,
            'canProceed' => $canProceed,
        ]);
    }

    /** 2. Adim: veritabani formu */
    public function database(): View
    {
        return view('install.database', ['step' => 2]);
    }

    /** 2. Adim POST: baglan, .env yaz, migrasyon */
    public function saveDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string'],
        ]);

        $pass = $data['db_password'] ?? '';

        // 1) Sunucuya baglan, gerekirse veritabanini olustur, sonra dogrula
        try {
            $server = new PDO(
                "mysql:host={$data['db_host']};port={$data['db_port']}",
                $data['db_username'],
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            try {
                $server->exec("CREATE DATABASE IF NOT EXISTS `{$data['db_database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (\Throwable $e) {
                // CREATE yetkisi olmayabilir; veritabani zaten varsa asagidaki dogrulama yeterli
            }
            new PDO(
                "mysql:host={$data['db_host']};port={$data['db_port']};dbname={$data['db_database']}",
                $data['db_username'],
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Veritabanina baglanilamadi: '.$e->getMessage());
        }

        // 2) .env'e yaz
        EnvWriter::write([
            'APP_NAME' => $data['app_name'],
            'APP_URL' => $data['app_url'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $pass,
        ]);

        // 3) Calisan baglantiyi guncelle ve migrasyonlari uygula
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $data['db_host'],
            'database.connections.mysql.port' => $data['db_port'],
            'database.connections.mysql.database' => $data['db_database'],
            'database.connections.mysql.username' => $data['db_username'],
            'database.connections.mysql.password' => $pass,
        ]);
        DB::purge('mysql');

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Migrasyon hatasi: '.$e->getMessage());
        }

        return redirect()->route('install.admin')
            ->with('status', 'Veritabani hazirlandi. Simdi yonetici hesabini olusturun.');
    }

    /** 3. Adim: yonetici formu */
    public function admin(): View|RedirectResponse
    {
        try {
            if (! Schema::hasTable('users')) {
                return redirect()->route('install.database')->with('error', 'Once veritabanini yapilandirin.');
            }
        } catch (\Throwable $e) {
            return redirect()->route('install.database')->with('error', 'Veritabani baglantisi yok; once yapilandirin.');
        }

        return view('install.admin', ['step' => 3]);
    }

    /** 3. Adim POST: yonetici olustur + kurulumu kilitle */
    public function saveAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // User modelindeki 'hashed' cast otomatik hashler
            ]);
            $user->settings(); // varsayilan ayar kaydi
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Yonetici olusturulamadi: '.$e->getMessage());
        }

        // Kurulum kilidi (artik /install kapanir)
        file_put_contents(storage_path('app/installed.lock'), 'installed: '.now()->toDateTimeString().PHP_EOL);

        return redirect()->route('login')
            ->with('status', 'Kurulum tamamlandi! Yonetici hesabinizla giris yapabilirsiniz.');
    }
}
