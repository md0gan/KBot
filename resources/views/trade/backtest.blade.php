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
                <input type="number" name="bars" min="50" max="1000" value="{{ $bars }}" class="rounded-lg border-slate-300 text-sm w-28">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Komisyon (%)</label>
                <input type="number" name="fee" step="0.01" min="0" max="5" value="{{ $fee }}" class="rounded-lg border-slate-300 text-sm w-24">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Kayma (%)</label>
                <input type="number" name="slip" step="0.01" min="0" max="5" value="{{ $slip }}" class="rounded-lg border-slate-300 text-sm w-24">
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

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
                <h2 class="font-semibold mb-3">Equity Eğrisi <span class="text-xs font-normal text-slate-400">(strateji vs al-tut)</span></h2>
                <div style="height: 260px;"><canvas id="equityChart"></canvas></div>
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

                <div class="mt-4 rounded-lg bg-sky-50 border border-sky-100 px-3 py-2 text-xs text-sky-800">
                    <strong>Toplam K/Z = Gerçekleşen K/Z + açık pozisyonun değer farkı.</strong>
                    "Gerçekleşen" sadece <em>kapanmış</em> işlemlerin sonucudur; henüz satılmamış (açık) pozisyon dahil değildir.
                    Bu yüzden kazanma oranı %100 olsa bile, elde tutulan zararlı pozisyon nedeniyle Toplam K/Z negatif olabilir.
                    Stratejiyi değerlendirirken <strong>Toplam K/Z</strong>'yi ve <strong>Al-Tut</strong> kıyasını esas alın.
                </div>

                <p class="mt-4 text-xs text-slate-400">
                    Not: Komisyon (%{{ $fee }}) ve kayma (%{{ $slip }}) modele dahildir; ancak emir dolum gecikmeleri,
                    likidite ve kısmi dolumlar hesaba katılmaz. Yalnızca fikir vermek içindir; gerçek sonuçlar farklılık gösterir.
                </p>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
            <script>
                (function () {
                    var c = @json($result['chart']);
                    var el = document.getElementById('equityChart');
                    if (!el || !window.Chart) return;
                    new Chart(el, {
                        type: 'line',
                        data: {
                            labels: c.labels,
                            datasets: [
                                { label: 'Strateji', data: c.equity, borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,0.12)', fill: true, tension: 0.2, pointRadius: 0, borderWidth: 2 },
                                { label: 'Al-Tut', data: c.buyhold, borderColor: '#94a3b8', borderDash: [5, 5], fill: false, tension: 0.2, pointRadius: 0, borderWidth: 1.5 }
                            ]
                        },
                        options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { position: 'bottom' } }, scales: { x: { display: false } } }
                    });
                })();
            </script>
        @endif
    </div>
@endsection
