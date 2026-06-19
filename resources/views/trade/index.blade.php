@extends('layouts.app')
@section('title', 'Trade')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold">Trade / Scalp</h1>
            <p class="text-sm text-slate-500">Yatırım modundan tamamen ayrı izlenir.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('trade.orders') }}" class="px-3 py-2 text-sm rounded-lg bg-white border border-slate-300 hover:bg-slate-50">İşlem geçmişi</a>
            <a href="{{ route('trade.create') }}" class="px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700 font-semibold">+ Trade botu ekle</a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Açık Maliyet</div>
            <div class="text-2xl font-bold mt-1">{{ kb_money($invested) }} <span class="text-sm text-slate-400">{{ $quote }}</span></div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Güncel Değer</div>
            <div class="text-2xl font-bold mt-1">{{ kb_money($currentValue) }} <span class="text-sm text-slate-400">{{ $quote }}</span></div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Açık K/Z</div>
            <div class="text-2xl font-bold mt-1 {{ $unrealized >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $unrealized >= 0 ? '+' : '' }}{{ kb_money($unrealized) }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Gerçekleşen K/Z ({{ $quote }})</div>
            <div class="text-2xl font-bold mt-1 {{ $realized >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $realized >= 0 ? '+' : '' }}{{ kb_money($realized) }}</div>
        </div>
    </div>

    <h2 class="text-lg font-semibold mb-3">Trade Botları</h2>
    @if ($bots->isEmpty())
        <div class="bg-white rounded-xl p-8 text-center text-slate-500 border border-dashed border-slate-300">
            Henüz trade botu yok.
            <a href="{{ route('trade.create') }}" class="text-sky-600 font-medium hover:underline">İlk botunuzu ekleyin →</a>
        </div>
    @else
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($bots as $bot)
                @php
                    $pos = $bot->position;
                    $val = (float) ($pos->last_value ?? ($pos->cost_basis ?? 0));
                    $pnl = $val - (float) ($pos->cost_basis ?? 0);
                @endphp
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between">
                        <a href="{{ route('trade.show', $bot) }}" class="font-bold text-lg hover:text-sky-600">{{ $bot->symbol }}</a>
                        <div class="flex items-center gap-1">
                            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $bot->strategyLabel() }}</span>
                            @php($bmode = $bot->effectiveMode($globalMode))
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $bmode === 'live' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $bmode === 'live' ? 'canlı' : 'sim' }}</span>
                            @if ($bot->enabled)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">aktif</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">durdu</span>
                            @endif
                        </div>
                    </div>
                    @if ($bot->name)<div class="text-xs text-slate-400 mt-0.5">{{ $bot->name }}</div>@endif

                    <div class="mt-3 text-sm text-slate-600 grid grid-cols-2 gap-y-1">
                        <span>Bütçe</span><span class="text-right font-medium">{{ kb_money($bot->budget) }} {{ $bot->quote_asset }}</span>
                        <span>Maliyet</span><span class="text-right font-medium">{{ kb_money($pos->cost_basis ?? 0) }}</span>
                        <span>Değer</span><span class="text-right font-medium">{{ kb_money($val) }}</span>
                        <span>Açık K/Z</span><span class="text-right font-medium {{ $pnl >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $pnl >= 0 ? '+' : '' }}{{ kb_money($pnl) }}</span>
                        <span>Gerçekleşen</span><span class="text-right font-medium {{ ($pos->realized_profit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ ($pos->realized_profit ?? 0) >= 0 ? '+' : '' }}{{ kb_money($pos->realized_profit ?? 0) }}</span>
                    </div>
                    @if ($bot->last_signal)
                        <div class="mt-2 text-xs text-slate-500">Sinyal: {{ $bot->last_signal }}</div>
                    @endif

                    <div class="mt-4 flex gap-2">
                        <form method="POST" action="{{ route('trade.run', $bot) }}" class="flex-1">@csrf
                            <button class="w-full text-sm px-2 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Çalıştır</button>
                        </form>
                        <a href="{{ route('trade.show', $bot) }}" class="text-sm px-3 py-1.5 rounded-lg bg-white border border-slate-300 hover:bg-slate-50">Detay</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="text-lg font-semibold mt-8 mb-3">Son Trade İşlemleri</h2>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @include('trade._orders_table', ['orders' => $recentOrders, 'paginated' => false])
    </div>
@endsection
