<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TradingBot;
use Illuminate\Console\Command;

class RunDcaBuys extends Command
{
    protected $signature = 'bot:dca {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Vadesi gelen coinler icin duzenli alim (DCA) yapar';

    public function handle(): int
    {
        $query = User::query()->whereHas('coins');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $any = false;
        $query->chunk(50, function ($users) use (&$any) {
            foreach ($users as $user) {
                try {
                    $bot = new TradingBot($user);
                    foreach ($bot->runDueBuys() as $line) {
                        $any = true;
                        $this->line("#{$user->id} {$line}");
                    }
                } catch (\Throwable $e) {
                    $this->error("#{$user->id} hata: ".$e->getMessage());
                }
            }
        });

        if (! $any) {
            $this->info('Islem yapilacak coin yok.');
        }

        return self::SUCCESS;
    }
}
