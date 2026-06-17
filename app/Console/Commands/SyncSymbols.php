<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TradingBot;
use Illuminate\Console\Command;

class SyncSymbols extends Command
{
    protected $signature = 'bot:sync-symbols {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Borsadan sembol filtrelerini (lot/step/minNotional) gunceller';

    public function handle(): int
    {
        $query = User::query()->whereHas('coins');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                try {
                    $n = (new TradingBot($user))->syncSymbols();
                    $this->line("#{$user->id}: {$n} coin guncellendi.");
                } catch (\Throwable $e) {
                    $this->error("#{$user->id} hata: ".$e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
}
