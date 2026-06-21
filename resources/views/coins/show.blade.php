@extends('layouts.app')
@section('title', $coin->symbol)

@php
    $pos = $coin->position;
    $cost = (float) ($pos->cost_basis ?? 0);
    $val = (float) ($pos->last_value ?? $cost);
    $ratio = $cost > 0 ? $val / $cost : 0;
    $mult = (float) $coin->profit_multiplier;
    $progress = $mult > 0 ? min(100, max(0, $ratio / $mult * 100)) : 0;
    $pnl = $val - $cost;
@endphp

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold">{{ $coin->symbol }}</h1>
            @if ($coin->name)<span class="text-sm text-slate-400">· {{ $coin->name }}</span>@endif
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ kb_mult($coin->profit_multiplier) }}x</span>
            @if ($coin->enabled)
                <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">aktif</span>
            @else
                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">durdu</span>
            @endif
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ config('bot.modes')[$coin->mode] ?? $coin->mode }}</span>
        </div>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('coins.toggle', $coin) }}">@csrf
                <button class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">{{ $coin->enabled ? 'Durdur' : 'Başlat' }}</button>
            </form>
            <a href="{{ route('coins.edit', $coin) }}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Düzenle</a>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Sol: pozisyon & aksiyonlar --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">Pozisyon</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Miktar</dt><dd class="font-medium">{{ kb_qty($pos->quantity ?? 0) }} {{ $coin->base_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Sermaye</dt><dd class="font-medium">{{ kb_money($cost) }} {{ $coin->quote_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Ort. maliyet</dt><dd class="font-medium">{{ kb_price($pos->avg_price ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Son fiyat</dt><dd class="font-medium">{{ kb_price($pos->last_price ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Güncel değer</dt><dd class="font-medium">{{ kb_money($val) }} {{ $coin->quote_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Açık K/Z</dt><dd class="font-medium {{ $pnl >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $pnl >= 0 ? '+' : '' }}{{ kb_money($pnl) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Çevrilen kar</dt><dd class="font-medium text-emerald-600">+{{ kb_money($pos->realized_profit ?? 0) }} {{ $coin->quote_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Kar-al sayısı</dt><dd class="font-medium">{{ $pos->profit_takes_count ?? 0 }}</dd></div>
                </dl>
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-slate-500 mb-1">
                        <span>Hedef {{ kb_mult($mult) }}x</span><span>{{ number_format($ratio, 2) }}x</span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full {{ $progress >= 100 ? 'bg-emerald-500' : 'bg-sky-500' }}" style="width: {{ $progress }}%"></div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">İşlemler</h2>
                <div class="grid grid-cols-2 gap-2">
                    <form method="POST" action="{{ route('coins.buy', $coin) }}">@csrf
                        <button class="w-full px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">Şimdi al ({{ kb_money($coin->buy_amount) }})</button>
                    </form>
                    <form method="POST" action="{{ route('coins.evaluate', $coin) }}">@csrf
                        <button class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Değerlendir</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('coins.sell', $coin) }}" class="mt-3 flex gap-2"
                      onsubmit="return confirm('Satış yapılsın mı?');">
                    @csrf
                    <input type="number" name="quantity" step="0.00000001" min="0" placeholder="Satılacak miktar"
                           class="flex-1 rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                    <button class="px-3 py-2 text-sm rounded-lg bg-amber-500 text-white hover:bg-amber-400">Sat</button>
                </form>
                <form method="POST" action="{{ route('coins.sell', $coin) }}" class="mt-2"
                      onsubmit="return confirm('Tüm pozisyon satılsın mı?');">
                    @csrf
                    <input type="hidden" name="all" value="1">
                    <button class="w-full px-3 py-2 text-sm rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50">Tümünü sat</button>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">Strateji</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Alım</dt><dd>{{ kb_money($coin->buy_amount) }} {{ $coin->quote_asset }} / {{ config('bot.intervals')[$coin->interval] ?? $coin->interval }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Kar-al</dt><dd>{{ config('bot.take_profit_strategies')[$coin->take_profit_strategy] ?? $coin->take_profit_strategy }}</dd></div>
                    @if ($coin->take_profit_strategy === 'fixed_ratio')
                        <div class="flex justify-between"><dt class="text-slate-500">Satış oranı</dt><dd>%{{ kb_money($coin->sell_ratio * 100, 0) }}</dd></div>
                    @endif
                    @if ($coin->max_buy_price)
                        <div class="flex justify-between"><dt class="text-slate-500">Maks. alım fiyatı</dt><dd>{{ kb_price($coin->max_buy_price) }} {{ $coin->quote_asset }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Sonraki alım</dt><dd>{{ $coin->next_buy_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Min. lot / notional</dt><dd>{{ $coin->step_size ? kb_qty($coin->step_size) : '—' }} / {{ $coin->min_notional ? kb_money($coin->min_notional) : '—' }}</dd></div>
                </dl>
                @if ($coin->notes)
                    <p class="mt-3 text-sm text-slate-500 border-t border-slate-100 pt-3">{{ $coin->notes }}</p>
                @endif
            </div>
        </div>

        {{-- Sag: islem gecmisi & loglar --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 font-semibold">İşlem Geçmişi</div>
                @include('trades._table', ['trades' => $trades, 'paginated' => true])
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 font-semibold">Bot Günlüğü</div>
                <ul class="divide-y divide-slate-100 text-sm max-h-80 overflow-y-auto">
                    @forelse ($logs as $log)
                        <li class="px-5 py-2 flex gap-3">
                            <span class="text-xs px-2 py-0.5 rounded h-fit {{ $log->level === 'error' ? 'bg-red-100 text-red-700' : ($log->level === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">{{ $log->level }}</span>
                            <div>
                                <div>{{ $log->message }}</div>
                                <div class="text-xs text-slate-400">{{ $log->created_at->format('d.m.Y H:i:s') }}</div>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-slate-400">Kayıt yok.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
