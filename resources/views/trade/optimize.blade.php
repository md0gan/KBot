@extends('layouts.app')
@section('title', 'Optimize · '.$tradeBot->symbol)

@section('content')
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Parametre Optimizasyonu — {{ $tradeBot->symbol }} <span class="text-sm font-normal text-slate-500">({{ $tradeBot->strategyLabel() }})</span></h1>
            <a href="{{ route('trade.show', $tradeBot) }}" class="text-sm text-slate-500 hover:text-slate-700">← Detay</a>
        </div>

        <p class="text-sm text-slate-500 mb-4">
            Botun diğer ayarları (mod, bütçe vb.) sabit kalır; stratejiye göre seçili parametreler bir ızgarada taranır,
            her kombinasyon aynı geçmiş veride backtest edilir ve <strong>Toplam K/Z</strong>'ye göre sıralanır.
            <strong>Sonuç otomatik uygulanmaz</strong> — beğendiğin kombinasyonu Düzenle'den elle girersin.
        </p>

        <form method="GET" action="{{ route('trade.optimize', $tradeBot) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap gap-4 items-end">
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
                <label class="block text-xs text-slate-500 mb-1">Mum Sayısı (80–1000)</label>
                <input type="number" name="bars" min="80" max="1000" value="{{ $bars }}" class="rounded-lg border-slate-300 text-sm w-28">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Komisyon (%)</label>
                <input type="number" name="fee" step="0.01" min="0" max="5" value="{{ $fee }}" class="rounded-lg border-slate-300 text-sm w-24">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Kayma (%)</label>
                <input type="number" name="slip" step="0.01" min="0" max="5" value="{{ $slip }}" class="rounded-lg border-slate-300 text-sm w-24">
            </div>
            <button class="px-4 py-2 text-sm rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Optimize et</button>
        </form>

        @if ($error)
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ $error }}</div>
        @endif

        @if (! empty($results))
            @php
                $q = $tradeBot->quote_asset;
                $labels = ['percent' => 'Adım %', 'v2_step_pct' => 'Düşüş adımı %', 'levels' => 'Kademe', 'sell_profit_pct' => 'Satış kârı %', 'atr_mult' => 'ATR ×', 'period' => 'Periyot', 'oversold' => 'Aşırı satım', 'overbought' => 'Aşırı alım', 'short' => 'Kısa MA', 'long' => 'Uzun MA', 'fast' => 'Hızlı', 'slow' => 'Yavaş', 'signal' => 'Sinyal', 'k' => 'K'];
                $paramKeys = array_keys($results[0]['params']);
            @endphp
            <div class="text-sm text-slate-500 mb-2">{{ $tested }} kombinasyon denendi · en iyi {{ count($results) }} gösteriliyor (Toplam K/Z'ye göre).</div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="text-left font-medium px-3 py-2">#</th>
                            @foreach ($paramKeys as $pk)
                                <th class="text-right font-medium px-3 py-2">{{ $labels[$pk] ?? $pk }}</th>
                            @endforeach
                            <th class="text-right font-medium px-3 py-2">Toplam K/Z</th>
                            <th class="text-right font-medium px-3 py-2">%</th>
                            <th class="text-right font-medium px-3 py-2">Kazanma</th>
                            <th class="text-right font-medium px-3 py-2">İşlem</th>
                            <th class="text-right font-medium px-3 py-2">Al-Tut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($results as $i => $row)
                            <tr class="{{ $i === 0 ? 'bg-emerald-50' : '' }}">
                                <td class="px-3 py-2 text-slate-500">{{ $i + 1 }}</td>
                                @foreach ($paramKeys as $pk)
                                    <td class="px-3 py-2 text-right">{{ $row['params'][$pk] }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-right font-medium {{ $row['m']['total_pl'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $row['m']['total_pl'] >= 0 ? '+' : '' }}{{ kb_money($row['m']['total_pl']) }} {{ $q }}</td>
                                <td class="px-3 py-2 text-right">%{{ number_format($row['m']['pl_pct'], 2) }}</td>
                                <td class="px-3 py-2 text-right">%{{ number_format($row['m']['win_rate'], 1) }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['m']['trades'] }}</td>
                                <td class="px-3 py-2 text-right text-slate-400">%{{ number_format($row['m']['buy_hold_pct'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-xs text-slate-400">
                En iyi satır yeşil işaretli. Sonuçları <strong>Al-Tut</strong> kıyası ve <strong>işlem sayısı</strong> ile birlikte
                değerlendir (çok az işlemli yüksek K/Z aldatıcı olabilir). Komisyon/kayma dahildir; emir gecikmeleri/likidite hariç.
                Geçmiş performans geleceği garanti etmez — yalnızca fikir vermek içindir.
            </p>
        @elseif (request()->filled('run') && ! $error)
            <div class="text-sm text-slate-500">Sonuç bulunamadı.</div>
        @else
            <div class="text-sm text-slate-500">Parametreleri seçip <strong>Optimize et</strong>'e basın.</div>
        @endif
    </div>
@endsection
