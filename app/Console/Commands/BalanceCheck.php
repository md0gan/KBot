<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Trade\TradeEngine;
use App\Services\TradingBot;
use Illuminate\Console\Command;

class BalanceCheck extends Command
{
    protected $signature = 'bot:balance-check {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Canli kote (TRY) bakiyesini kontrol eder; azalma/dusuk bakiye ve trade butce eksigi olunca Telegram bildirir';

    public function handle(): int
    {
        // Yatirim coini VEYA trade botu olan kullanicilar.
        $query = User::query()->where(function ($q) {
            $q->whereHas('coins')->orWhereHas('tradeBots');
        });
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                // Yatirim: kote bakiye azalmasi / dusuk bakiye
                try {
                    $res = (new TradingBot($user))->checkBalance();
                    if ($res) {
                        $this->line("#{$user->id}: {$res['free']} {$res['quote']} (uyari: {$res['alerts']})");
                    }
                } catch (\Throwable $e) {
                    $this->error("#{$user->id} bakiye: ".$e->getMessage());
                }

                // Trade: canli botlarin butcesi icin yeterli kote var mi?
                try {
                    $cov = (new TradeEngine($user))->checkBudgetCoverage();
                    foreach ($cov ?? [] as $c) {
                        $this->line("#{$user->id} trade {$c['quote']}: gereken {$c['required']} / serbest {$c['free']}".($c['short'] ? ' [YETERSIZ]' : ''));
                    }
                } catch (\Throwable $e) {
                    $this->error("#{$user->id} trade butce: ".$e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
}
