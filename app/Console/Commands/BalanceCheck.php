<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TradingBot;
use Illuminate\Console\Command;

class BalanceCheck extends Command
{
    protected $signature = 'bot:balance-check {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Canli kote (TRY) bakiyesini kontrol eder; azalma/dusuk bakiye olunca Telegram bildirir';

    public function handle(): int
    {
        $query = User::query()->whereHas('coins');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                try {
                    $res = (new TradingBot($user))->checkBalance();
                    if ($res) {
                        $this->line("#{$user->id}: {$res['free']} {$res['quote']} (uyari: {$res['alerts']})");
                    }
                } catch (\Throwable $e) {
                    $this->error("#{$user->id}: ".$e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
}
