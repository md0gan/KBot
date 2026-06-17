<?php

use Illuminate\Support\Facades\Artisan;

// Zamanlama tanimlari bootstrap/app.php icindeki withSchedule() blogundadir.
// Buraya ek artisan closure komutlari eklenebilir.

Artisan::command('bot:ping', function () {
    $this->info('KBot calisiyor: '.now()->toDateTimeString());
})->purpose('Botun ayakta oldugunu test eder');
