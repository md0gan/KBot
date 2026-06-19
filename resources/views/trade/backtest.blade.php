@extends('layouts.app')
@section('title', 'Backtest · '.$tradeBot->symbol)

@section('content')
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Backtest — {{ $tradeBot->symbol }} <span class="text-sm font-normal text-slate-500">({{ $tradeBot->strategyLabel() }})</span></h1>
            <a href="{{ route('trade.show', $tradeBot) }}" class="text-sm text-slate-500 hover:text-slate-700">← Detay</a>
        </div>

        <p class="text-sm text-slate-500 mb-4">
            Botun <strong>kayıtlı ayarlarıyla</strong> geçmiş mum verisi üzerinde simülasyon yapılır (gerçek emir verilmez).
            Farklı parametre denemek için botu düzenleyip tekrar çalıştırın.
        </p>

        <form method="GET" action="{{ route('trade.backtest', $tradeBot) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap gap-4 items-end">
            <input type="hidden" name="run" value="1">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Zaman Dilimi</label>
                <select name="interval" class="rounded-lg border-slate-300 text-sm">
                    @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                        <option value="{{ $iv }}" @selected($interval === $iv)>{{ $iv }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Mum Sayısı (50–1000)</label>
                <input type="number" name="bars" min="50" max="1000" value="{{ $bars }}" class="rounded-lg border-slate-300 text-sm w-32">
            </div>
            <button class="px-4 py-2 text-sm rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Backtest çalıştır</button>
        </form>

        @if ($error)
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ $error }}</div>
        @endif

        @if ($result)
            @php($q = $tradeBot->quote_asset)
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Toplam K/Z</div>
                    <div class="text-2xl font-bold mt-1 {{ $result['total_pl'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $result['total_pl'] >= 0 ? '+' : '' }}{{ kb_money($result['total_pl']) }} <span class="text-sm text-slate-400">{{ $q }}</span></div>
                    <div class="text-xs text-slate-400 mt-1">%{{ number_format($result['pl_pct'], 2) }}</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Al-Tut Getirisi</div>
                    <div class="text-2xl font-bold mt-1 {{ $result['buy_hold_pct'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">%{{ number_format($result['buy_hold_pct'], 2) }}</div>
                    <div class="text-xs text-slate-400 mt-1">aynı dönem fiyat değişimi</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="text-xs text-slate-500 uppercase tracking-wide">İşlem Sayısı</div>
                    <div class="text-2xl font-bold mt-1">{{ $result['trades'] }}</div>
                    <div class="text-xs text-slate-400 mt-1">{{ $result['wins'] }} kazanç / {{ $result['losses'] }} zarar</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Kazanma Oranı</div>
                    <div class="text-2xl font-bold mt-1">%{{ number_format($result['win_rate'], 1) }}</div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-semibold mb-3">Detay</h2>
                <dl class="text-sm grid sm:grid-cols-2 gap-x-8 gap-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Mum sayısı</dt><dd>{{ $result['bars'] }} ({{ $interval }})</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Başlangıç fiyatı</dt><dd>{{ kb_price($result['start_price']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Bitiş fiyatı</dt><dd>{{ kb_price($result['end_price']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Gerçekleşen K/Z</dt><dd class="{{ $result['realized'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $result['realized'] >= 0 ? '+' : '' }}{{ kb_money($result['realized']) }} {{ $q }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Açık pozisyon değeri</dt><dd>{{ kb_money($result['open_value']) }} {{ $q }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Kullanılan tutar</dt><dd>{{ kb_money($result['invested']) }} {{ $q }}</dd></div>
                </dl>
                <p class="mt-4 text-xs text-slate-400">
                    Not: Basit simülasyon — komisyon, kayma (slippage) ve emir dolum gecikmeleri hesaba katılmaz.
                    Gerçek sonuçlar farklılık gösterir; yalnızca fikir vermek içindir.
                </p>
            </div>
        @endif
    </div>
@endsection
