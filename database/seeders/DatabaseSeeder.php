<?php

namespace Database\Seeders;

use App\Models\Coin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo / ilk admin kullanicisi
        $user = User::firstOrCreate(
            ['email' => 'admin@kbot.local'],
            [
                'name' => 'KBot Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Ayarlar (simulasyon modunda baslar)
        $user->settings()->update([
            'trading_mode' => 'simulation',
            'default_quote' => 'TRY',
            'bot_enabled' => true,
        ]);

        // Ornek coinler (simulasyon). API anahtari gerekmeden fiyat cekilebilir.
        $samples = [
            ['base' => 'BTC', 'amount' => 10, 'mult' => 2.0],
            ['base' => 'ETH', 'amount' => 10, 'mult' => 2.0],
        ];

        foreach ($samples as $s) {
            Coin::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $s['base'].'_USDT'],
                [
                    'base_asset' => $s['base'],
                    'quote_asset' => 'USDT',
                    'symbol_type' => 1,
                    'enabled' => false, // guvenlik icin kapali baslar; panelden acilir
                    'mode' => 'inherit',
                    'buy_amount' => $s['amount'],
                    'interval' => 'weekly',
                    'buy_hour' => 9,
                    'profit_multiplier' => $s['mult'],
                    'take_profit_strategy' => 'leave_capital',
                    'sell_ratio' => 0.5,
                    'next_buy_at' => now(),
                ]
            );
        }
    }
}
