@extends('layouts.app')
@section('title', 'Trade Performans')

@section('content')
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Trade Performans</h1>
            <a href="{{ route('trade.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Trade</a>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Gerçekleşen K/Z</div>
                <div class="text-xl font-bold mt-1 {{ $totalRealized >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $totalRealized >= 0 ? '+' : '' }}{{ kb_money($totalRealized) }} <span class="text-xs text-slate-400">{{ $quote }}</span></div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Açık K/Z</div>
                <div class="text-xl font-bold mt-1 {{ $openPl >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $openPl >= 0 ? '+' : '' }}{{ kb_money($openPl) }}</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Kapanan İşlem</div>
                <div class="text-xl font-bold mt-1">{{ $totalTrades }}</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Kazanma Oranı</div>
                <div class="text-xl font-bold mt-1">%{{ number_format($winRate, 1) }}</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Ödenen Komisyon</div>
                <div class="text-xl font-bold mt-1">{{ kb_money($totalFees) }} <span class="text-xs text-slate-400">{{ $quote }}</span></div>
            </div>
        </div>

        @if (count($labels) > 1)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
                <h2 class="font-semibold mb-3">Kümülatif Gerçekleşen K/Z</h2>
                <div style="height: 260px;"><canvas id="kb-perf-chart"></canvas></div>
            </div>
        @endif

        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 font-semibold">Stratejiye Göre</div>
                @include('trade._perf_table', ['rows' => $byStrategy, 'label' => 'Strateji', 'quote' => $quote])
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 font-semibold">Coine Göre</div>
                @include('trade._perf_table', ['rows' => $bySymbol, 'label' => 'Coin', 'quote' => $quote])
            </div>
        </div>

        <p class="mt-4 text-xs text-slate-400">
            Gerçekleşen K/Z yalnızca <strong>kapanmış</strong> (satılmış) işlemlerden gelir; açık pozisyonların
            değer farkı "Açık K/Z"de gösterilir. Kümülatif eğri, kapanan işlemlerin güne göre toplamıdır.
        </p>
    </div>

    @if (count($labels) > 1)
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
            (function () {
                var el = document.getElementById('kb-perf-chart');
                if (!el || !window.Chart) return;
                new Chart(el, {
                    type: 'line',
                    data: {
                        labels: @json($labels),
                        datasets: [{ label: 'Kümülatif K/Z', data: @json($cum), borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,0.12)', fill: true, tension: 0.2, pointRadius: 0, borderWidth: 2 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } } } }
                });
            })();
        </script>
    @endif
@endsection
