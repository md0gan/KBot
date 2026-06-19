@extends('layouts.app')
@section('title', $tradeBot->symbol.' · Trade')

@php
    $pos = $tradeBot->position;
    $cost = (float) ($pos->cost_basis ?? 0);
    $val = (float) ($pos->last_value ?? $cost);
    $pnl = $val - $cost;
    $p = $tradeBot->params ?? [];
@endphp

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold">{{ $tradeBot->symbol }}</h1>
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $tradeBot->strategyLabel() }}</span>
            @if ($tradeBot->enabled)
                <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">aktif</span>
            @else
                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">durdu</span>
            @endif
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ config('bot.modes')[$tradeBot->mode] ?? $tradeBot->mode }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('trade.run', $tradeBot) }}">@csrf
                <button class="px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">▶ Çalıştır</button>
            </form>
            <form method="POST" action="{{ route('trade.toggle', $tradeBot) }}">@csrf
                <button class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">{{ $tradeBot->enabled ? 'Durdur' : 'Başlat' }}</button>
            </form>
            @if ($tradeBot->strategy === 'grid')
                <form method="POST" action="{{ route('trade.rebuild-grid', $tradeBot) }}"
                      onsubmit="return confirm('Grid kademeleri yeniden kurulsun mu? Mevcut kademe durumları sıfırlanır.');">@csrf
                    <button class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Grid'i yeniden kur</button>
                </form>
            @endif
            <a href="{{ route('trade.backtest', $tradeBot) }}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Backtest</a>
            <a href="{{ route('trade.edit', $tradeBot) }}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Düzenle</a>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">Pozisyon</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Miktar</dt><dd class="font-medium">{{ kb_qty($pos->quantity ?? 0) }} {{ $tradeBot->base_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Maliyet</dt><dd class="font-medium">{{ kb_money($cost) }} {{ $tradeBot->quote_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Ort. maliyet</dt><dd class="font-medium">{{ kb_price($pos->avg_price ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Son fiyat</dt><dd class="font-medium">{{ kb_price($pos->last_price ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Değer</dt><dd class="font-medium">{{ kb_money($val) }} {{ $tradeBot->quote_asset }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Açık K/Z</dt><dd class="font-medium {{ $pnl >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $pnl >= 0 ? '+' : '' }}{{ kb_money($pnl) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Gerçekleşen K/Z</dt><dd class="font-medium {{ ($pos->realized_profit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ ($pos->realized_profit ?? 0) >= 0 ? '+' : '' }}{{ kb_money($pos->realized_profit ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">İşlem sayısı</dt><dd class="font-medium">{{ $pos->trades_count ?? 0 }}</dd></div>
                </dl>
                @if (($pos->quantity ?? 0) > 0)
                    <form method="POST" action="{{ route('trade.sell-all', $tradeBot) }}" class="mt-4"
                          onsubmit="return confirm('Tüm pozisyon satılsın mı?');">@csrf
                        <button class="w-full px-3 py-2 text-sm rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50">Tüm pozisyonu sat</button>
                    </form>
                @endif
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">Strateji Ayarları</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Strateji</dt><dd>{{ $tradeBot->strategyLabel() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Bütçe</dt><dd>{{ kb_money($tradeBot->budget) }} {{ $tradeBot->quote_asset }}</dd></div>
                    @if ($tradeBot->max_buy_price)
                        <div class="flex justify-between"><dt class="text-slate-500">Maks. alım fiyatı</dt><dd>{{ kb_price($tradeBot->max_buy_price) }}</dd></div>
                    @endif
                    @if ($tradeBot->strategy === 'grid')
                        <div class="flex justify-between"><dt class="text-slate-500">Aralık</dt><dd>{{ ($p['range_mode'] ?? 'manual') === 'auto' ? 'Otomatik ±%'.($p['percent'] ?? '') : kb_price($p['lower'] ?? 0).' – '.kb_price($p['upper'] ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Kademe</dt><dd>{{ $p['levels'] ?? '—' }}</dd></div>
                    @elseif ($tradeBot->strategy === 'rsi')
                        <div class="flex justify-between"><dt class="text-slate-500">Zaman / Periyot</dt><dd>{{ $p['interval'] ?? '15m' }} / {{ $p['period'] ?? 14 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Eşikler</dt><dd>{{ $p['oversold'] ?? 30 }} / {{ $p['overbought'] ?? 70 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">İşlem tutarı</dt><dd>{{ kb_money($tradeBot->order_size) }}</dd></div>
                    @elseif ($tradeBot->strategy === 'ma_cross')
                        <div class="flex justify-between"><dt class="text-slate-500">Zaman dilimi</dt><dd>{{ $p['interval'] ?? '15m' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">MA</dt><dd>{{ strtoupper($p['ma_type'] ?? 'ema') }} {{ $p['short'] ?? 9 }}/{{ $p['long'] ?? 21 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">İşlem tutarı</dt><dd>{{ kb_money($tradeBot->order_size) }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Son sinyal</dt><dd>{{ $tradeBot->last_signal ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Son çalışma</dt><dd>{{ $tradeBot->last_run_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                </dl>
                @if ($tradeBot->notes)
                    <p class="mt-3 text-sm text-slate-500 border-t border-slate-100 pt-3">{{ $tradeBot->notes }}</p>
                @endif
            </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
            @if ($tradeBot->strategy === 'grid' && $levels->isNotEmpty())
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 font-semibold">Grid Kademeleri</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <tr>
                                    <th class="text-left font-medium px-4 py-2">#</th>
                                    <th class="text-right font-medium px-4 py-2">Alış</th>
                                    <th class="text-right font-medium px-4 py-2">Satış</th>
                                    <th class="text-center font-medium px-4 py-2">Durum</th>
                                    <th class="text-right font-medium px-4 py-2">Miktar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($levels as $lv)
                                    <tr>
                                        <td class="px-4 py-2 text-slate-500">{{ $lv->level_index }}</td>
                                        <td class="px-4 py-2 text-right">{{ kb_price($lv->buy_price) }}</td>
                                        <td class="px-4 py-2 text-right">{{ kb_price($lv->sell_price) }}</td>
                                        <td class="px-4 py-2 text-center">
                                            <span class="px-2 py-0.5 rounded text-xs {{ $lv->status === 'holding' ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-500' }}">{{ $lv->status === 'holding' ? 'tutuluyor' : 'bekliyor' }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right">{{ $lv->quantity > 0 ? kb_qty($lv->quantity) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 font-semibold">İşlem Geçmişi</div>
                @include('trade._orders_table', ['orders' => $orders, 'paginated' => true])
            </div>
        </div>
    </div>
@endsection
