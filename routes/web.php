<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\CoinController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kurulum sihirbazi (yalnizca kurulu degilken erisilebilir)
|--------------------------------------------------------------------------
*/
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('database', [InstallController::class, 'database'])->name('database');
    Route::post('database', [InstallController::class, 'saveDatabase'])->name('database.save');
    Route::get('admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('admin', [InstallController::class, 'saveAdmin'])->name('admin.save');
});

/*
|--------------------------------------------------------------------------
| Misafir (giris yapmamis) rotalari
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Kimlik dogrulamali rotalar
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Coin yonetimi
    Route::resource('coins', CoinController::class)->except(['show']);
    Route::get('coins/{coin}', [CoinController::class, 'show'])->name('coins.show');
    Route::post('coins/{coin}/toggle', [CoinController::class, 'toggle'])->name('coins.toggle');

    // Coin bazli bot islemleri
    Route::post('coins/{coin}/buy', [BotController::class, 'buyNow'])->name('coins.buy');
    Route::post('coins/{coin}/evaluate', [BotController::class, 'evaluateNow'])->name('coins.evaluate');
    Route::post('coins/{coin}/sell', [BotController::class, 'sell'])->name('coins.sell');

    // Genel bot islemleri
    Route::post('bot/run', [BotController::class, 'runAll'])->name('bot.run');
    Route::post('bot/sync', [BotController::class, 'sync'])->name('bot.sync');
    Route::post('bot/refresh', [BotController::class, 'refresh'])->name('bot.refresh');

    // Ayarlar
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/test', [SettingController::class, 'test'])->name('settings.test');
    Route::post('settings/telegram-test', [SettingController::class, 'testTelegram'])->name('settings.telegram-test');
    Route::post('settings/mode', [SettingController::class, 'toggleMode'])->name('settings.mode');

    // Hesap / sifre
    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    // Islem gecmisi & loglar
    Route::get('trades', [TradeController::class, 'index'])->name('trades.index');
    Route::get('logs', [TradeController::class, 'logs'])->name('logs.index');
});
