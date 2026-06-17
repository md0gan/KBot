@extends('layouts.app')
@section('title', 'Panel')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold">Panel</h1>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('bot.refresh') }}">@csrf
                <button class="px-3 py-2 text-sm rounded-lg bg-white border border-slate-300 hover:bg-slate-50">↻ Fiyatları yenile</button>
            </form>
            <form method="POST" action="{{ route('bot.sync') }}">@csrf
                <button class="px-3 py-2 text-sm rounded-lg bg-white border border-slate-300 hover:bg-slate-50">⇄ Sembolleri senkronla</button>
            </form>
            <form method="POST" action="{{ route('bot.run') }}"
                  onsubmit="return confirm('Vadesi gelen alımlar ve kar-al değerlendirmesi şimdi çalıştırılsın mı?');">@csrf
                <button class="px-3 py-2 text-sm rounded-lg bg-sky-600 text-white hover:bg-sky-500 font-semibold">▶ Botu şimdi çalıştır</button>
            </form>
            <a href="{{ route('coins.create') }}" class="px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700 font-semibold">+ Coin ekle</a>
        </div>
    </div>

    {{-- KPI kartlari --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Yatırılan Sermaye</div>
            <div class="text-2xl font-bold mt-1">{{ kb_money($invested) }} <span class="text-sm text-slate-400">{{ $setting->default_quote }}</span></div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Güncel Değer</div>
            <div class="text-2xl font-bold mt-1">{{ kb_money($currentValue) }} <span class="text-sm text-slate-400">USDT</span></div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Açık K/Z</div>
            <div class="text-2xl font-bold mt-1 {{ $unrealized >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                {{ $unrealized >= 0 ? '+' : '' }}{{ kb_money($unrealized) }}
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs text-slate-500 uppercase tracking-wide">USDT'ye Çevrilen Kar</div>
            <div class="text-2xl font-bold mt-1 text-emerald-600">+{{ kb_money($realized) }}</div>
        </div>
    </div>

    {{-- Coin kartlari --}}
    <h2 class="text-lg font-semibold mb-3">Coinler</h2>
    @if ($coins->isEmpty())
        <div class="bg-white rounded-xl p-8 text-center text-slate-500 border border-dashed border-slate-300">
            Henüz coin eklemediniz.
            <a href="{{ route('coins.create') }}" class="text-sky-600 font-medium hover:underline">İlk coininizi ekleyin →</a>
        </div>
    @else
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($coins as $coin)
                @php
                    $pos = $coin->position;
                    $cost = (float) ($pos->cost_basis ?? 0);
                    $val = (float) ($pos->last_value ?? $cost);
                    $ratio = $cost > 0 ? $val / $cost : 0;
                    $mult = (float) $coin->profit_multiplier;
                    $progress = $mult > 0 ? min(100, max(0, $ratio / $mult * 100)) : 0;
                    $pnl = $val - $cost;
                @endphp
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between">
                        <a href="{{ route('coins.show', $coin) }}" class="font-bold text-lg hover:text-sky-600">{{ $coin->symbol }}</a>
                        <div class="flex items-center gap-1">
                            @if ($coin->enabled)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">aktif</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">durdu</span>
                            @endif
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $coin->mode === 'live' ? 'bg-red-100 text-red-700' : ($coin->mode === 'simulation' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600') }}">
                                {{ config('bot.modes')[$coin->mode] ?? $coin->mode }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 text-sm text-slate-600 grid grid-cols-2 gap-y-1">
                        <span>Sermaye</span><span class="text-right font-medium">{{ kb_money($cost) }} {{ $coin->quote_asset }}</span>
                        <span>Güncel değer</span><span class="text-right font-medium">{{ kb_money($val) }} {{ $coin->quote_asset }}</span>
                        <span>K/Z</span><span class="text-right font-medium {{ $pnl >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $pnl >= 0 ? '+' : '' }}{{ kb_money($pnl) }}</span>
                        <span>Miktar</span><span class="text-right font-medium">{{ kb_qty($pos->quantity ?? 0) }} {{ $coin->base_asset }}</span>
                    </div>

                    <div class="mt-3">
                        <div class="flex justify-between text-xs text-slate-500 mb-1">
                            <span>Kar-al hedefi {{ rtrim(rtrim(number_format($mult,2,'.',''),'0'),'.') }}x</span>
                            <span>{{ number_format($ratio, 2) }}x</span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full {{ $progress >= 100 ? 'bg-emerald-500' : 'bg-sky-500' }}" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        <form method="POST" action="{{ route('coins.buy', $coin) }}" class="flex-1">@csrf
                            <button class="w-full text-sm px-2 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Al ({{ kb_money($coin->buy_amount) }})</button>
                        </form>
                        <form method="POST" action="{{ route('coins.evaluate', $coin) }}" class="flex-1">@csrf
                            <button class="w-full text-sm px-2 py-1.5 rounded-lg bg-white border border-slate-300 hover:bg-slate-50">Değerlendir</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Son islemler --}}
    <h2 class="text-lg font-semibold mt-8 mb-3">Son İşlemler</h2>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @include('trades._table', ['trades' => $recentTrades, 'paginated' => false])
    </div>
@endsection
