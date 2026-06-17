<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TradingBot;
use Illuminate\Console\Command;

class EvaluatePositions extends Command
{
    protected $signature = 'bot:evaluate {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Acik pozisyonlari degerlendirir ve carpana ulasanlarda kar-al yapar';

    public function handle(): int
    {
        $query = User::query()->whereHas('coins');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                try {
                    $bot = new TradingBot($user);
                    foreach ($bot->evaluateAll() as $line) {
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
