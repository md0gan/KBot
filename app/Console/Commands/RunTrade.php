<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Trade\TradeEngine;
use Illuminate\Console\Command;

class RunTrade extends Command
{
    protected $signature = 'bot:trade {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Etkin trade botlarini (grid / rsi / ma_cross) bir tur calistirir';

    public function handle(): int
    {
        $query = User::query()->whereHas('tradeBots');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                try {
                    foreach ((new TradeEngine($user))->runAll() as $line) {
                        $this->line("#{$user->id} {$line}");
                    }
                } catch (\Throwable $e) {
                    $this->error("#{$user->id} hata: ".$e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
}
