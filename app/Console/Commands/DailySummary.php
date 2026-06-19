<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * Telegram'i bagli her kullaniciya gunluk trade performans ozeti gonderir.
 * Zamanlayicidan gunde bir calisir (salt-okunur; islem yapmaz).
 */
class DailySummary extends Command
{
    protected $signature = 'bot:daily-summary {--user= : Sadece belirtilen kullanici ID}';

    protected $description = 'Telegram bagli kullanicilara gunluk trade performans ozeti gonderir';

    public function handle(): int
    {
        $query = User::query()->whereHas('tradeBots');
        if ($this->option('user')) {
            $query->whereKey($this->option('user'));
        }

        $query->chunk(50, function ($users) {
            foreach ($users as $user) {
                try {
                    $this->sendFor($user);
                } catch (\Throwable $e) {
                    $this->error("#{$user->id}: ".$e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }

    protected function sendFor(User $user): void
    {
        $setting = $user->settings();
        if (! $setting->hasTelegram()) {
            return;
        }

        $quote = $setting->default_quote ?: 'TRY';
        $since = now()->subDay();

        $sells24 = $user->tradeOrders()->where('side', 'SELL')
            ->where('executed_at', '>=', $since)
            ->get(['symbol', 'realized_profit']);
        $realized24 = (float) $sells24->sum('realized_profit');
        $trades24 = $sells24->count();

        $allRealized = (float) $user->tradeOrders()->where('side', 'SELL')->sum('realized_profit');

        $openPl = (float) $user->tradeBots()->with('position')->get()->sum(function ($b) {
            $p = $b->position;
            if (! $p) {
                return 0.0;
            }

            return (float) (($p->last_value ?? $p->cost_basis) - $p->cost_basis);
        });

        // En iyi/kotu bot (son 24 saat)
        $bestLine = '';
        $byBot = $sells24->groupBy('symbol')->map(fn ($g) => (float) $g->sum('realized_profit'))->sortDesc();
        if ($byBot->isNotEmpty()) {
            $sym = $byBot->keys()->first();
            $pl = $byBot->first();
            if ($pl != 0.0) {
                $bestLine = "\nEn iyi (24s): {$sym} ".($pl >= 0 ? '+' : '').kb_money($pl)." {$quote}";
            }
        }

        $msg = implode("\n", [
            '📊 KBot Günlük Özet — '.now()->format('d.m.Y'),
            '',
            'Son 24 saat:',
            '• Gerçekleşen K/Z: '.($realized24 >= 0 ? '+' : '').kb_money($realized24)." {$quote} ({$trades24} işlem)",
            '',
            'Şu an:',
            '• Açık K/Z: '.($openPl >= 0 ? '+' : '').kb_money($openPl)." {$quote}",
            '• Toplam gerçekleşen: '.($allRealized >= 0 ? '+' : '').kb_money($allRealized)." {$quote}",
        ]).$bestLine;

        if (TelegramNotifier::fromSetting($setting)->send($msg)) {
            $this->line("#{$user->id}: ozet gonderildi.");
        }
    }
}
