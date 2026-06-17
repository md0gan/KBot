<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Kurulu degilse /install'a yonlendiren kontrol (web grubuna eklenir).
        $middleware->web(append: [
            \App\Http\Middleware\CheckInstalled::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Vadesi gelen coinler icin duzenli alim (DCA). Komut kendi icinde
        // "next_buy_at <= now" kontrolu yaptigi icin sik calismasi guvenlidir.
        $schedule->command('bot:dca')->everyFiveMinutes()->withoutOverlapping();

        // Acik pozisyonlari degerlendir: carpana ulasan coinlerde kar-al.
        $schedule->command('bot:evaluate')->everyFiveMinutes()->withoutOverlapping();

        // Sembol filtrelerini (lot/step/minNotional) gunde bir guncelle.
        $schedule->command('bot:sync-symbols')->dailyAt('03:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
