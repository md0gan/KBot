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
            @php $emode = $tradeBot->effectiveMode(); @endphp
            <span class="text-xs px-2 py-0.5 rounded-full {{ $emode === 'live' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $emode === 'live' ? 'CANLI' : 'SİM' }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{{ config('bot.modes')[$tradeBot->mode] ?? $tradeBot->mode }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
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
            <a href="{{ route('trade.optimize', $tradeBot) }}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Optimize</a>
            <a href="{{ route('trade.edit', $tradeBot) }}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Düzenle</a>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var canvas = document.getElementById('kb-price-chart');
            if (!canvas) return;
            var sel = document.getElementById('kb-chart-interval');
            var empty = document.getElementById('kb-chart-empty');
            var base = "{{ route('trade.candles', $tradeBot) }}";
            var chart = null;
            var KEY = 'kb_chart_iv_{{ $tradeBot->id }}';
            try { var sv = localStorage.getItem(KEY); if (sv) sel.value = sv; } catch (e) {}

            function fmt(ts) {
                return new Date(ts).toLocaleString('tr-TR', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
            }
            function showEmpty(on) {
                canvas.style.display = on ? 'none' : '';
                if (empty) empty.classList.toggle('hidden', !on);
            }
            function load() {
                if (!window.Chart) return;
                fetch(base + '?interval=' + encodeURIComponent(sel.value), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var pts = (d && d.points) || [];
                        if (!pts.length) { showEmpty(true); return; }
                        showEmpty(false);
                        var labels = pts.map(function (p) { return fmt(p.t); });
                        var prices = pts.map(function (p) { return p.c; });

                        var datasets = [{
                            label: 'Fiyat', data: prices, borderColor: '#0ea5e9',
                            backgroundColor: 'rgba(14,165,233,0.10)', fill: true, tension: 0.15,
                            pointRadius: 0, borderWidth: 2, order: 0
                        }];
                        (d.grid || []).forEach(function (g) {
                            var holding = g.status === 'holding';
                            // Alış kademesi: yeşil ve belirgin (tutulan = düz/kalın, bekleyen = kesikli)
                            datasets.push({
                                label: '', data: labels.map(function () { return g.buy; }),
                                borderColor: holding ? 'rgba(16,185,129,1)' : 'rgba(16,185,129,0.8)',
                                borderDash: holding ? [] : [6, 3], borderWidth: holding ? 2.5 : 1.75,
                                pointRadius: 0, fill: false, tension: 0, order: 4
                            });
                            // Satış kademesi: kırmızı (ince kesikli)
                            datasets.push({
                                label: '', data: labels.map(function () { return g.sell; }),
                                borderColor: 'rgba(239,68,68,0.65)',
                                borderDash: [4, 4], borderWidth: 1,
                                pointRadius: 0, fill: false, tension: 0, order: 5
                            });
                        });

                        if (chart) chart.destroy();
                        chart = new Chart(canvas, {
                            type: 'line',
                            data: { labels: labels, datasets: datasets },
                            options: {
                                responsive: true, maintainAspectRatio: false, animation: false,
                                interaction: { intersect: false, mode: 'index' },
                                plugins: {
                                    legend: { display: true, labels: { filter: function (i) { return i.text; } } },
                                    tooltip: { callbacks: { label: function (c) { return c.dataset.label ? (c.dataset.label + ': ' + c.parsed.y) : null; } } }
                                },
                                scales: { x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } }, y: { position: 'right' } }
                            }
                        });
                    })
                    .catch(function () { showEmpty(true); });
            }
            sel.addEventListener('change', function () { try { localStorage.setItem(KEY, sel.value); } catch (e) {} load(); });
            load();
        });
    </script>

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
                    @if ($tradeBot->param('compounding', false))
                        <div class="flex justify-between"><dt class="text-slate-500">Compounding</dt><dd class="text-emerald-600">açık (kâr bütçeye ekleniyor)</dd></div>
                    @endif
                    @if ($tradeBot->param('max_loss_pct', 0) > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Zarar durdurma</dt><dd>bütçenin %{{ rtrim(rtrim(number_format($tradeBot->param('max_loss_pct'), 2, '.', ''), '0'), '.') }}'i</dd></div>
                    @endif
                    @if ($tradeBot->param('trail_tp_pct', 0) > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Trailing TP</dt><dd class="text-emerald-600">zirveden %{{ rtrim(rtrim(number_format($tradeBot->param('trail_tp_pct'), 2, '.', ''), '0'), '.') }} geri çekilince kapat</dd></div>
                    @endif
                    @if ($tradeBot->max_buy_price)
                        <div class="flex justify-between"><dt class="text-slate-500">Maks. alım fiyatı</dt><dd>{{ kb_price($tradeBot->max_buy_price) }}</dd></div>
                    @endif
                    @if ($tradeBot->strategy === 'grid')
                        @php
                            $rm = $p['range_mode'] ?? 'manual';
                            $anc = (($p['anchor'] ?? 'below') === 'below') ? ' (alım merdiveni)' : ' (simetrik)';
                            if ($rm === 'auto') {
                                $aralik = 'Otomatik · kademe %'.($p['percent'] ?? '').$anc;
                            } elseif ($rm === 'atr') {
                                $aralik = 'ATR ×'.($p['atr_mult'] ?? 1).' · '.($p['atr_interval'] ?? '1h').'/'.($p['atr_period'] ?? 14).$anc;
                            } else {
                                $aralik = kb_price($p['lower'] ?? 0).' – '.kb_price($p['upper'] ?? 0);
                            }
                        @endphp
                        <div class="flex justify-between"><dt class="text-slate-500">Aralık</dt><dd>{{ $aralik }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Kademe</dt><dd>{{ $p['levels'] ?? '—' }}</dd></div>
                        @if (($p['sell_profit_pct'] ?? 0) > 0)
                            <div class="flex justify-between"><dt class="text-slate-500">Sabit satış kârı</dt><dd class="text-emerald-600">her kademe alış +%{{ rtrim(rtrim(number_format($p['sell_profit_pct'], 2, '.', ''), '0'), '.') }}</dd></div>
                        @endif
                    @elseif ($tradeBot->strategy === 'rsi')
                        <div class="flex justify-between"><dt class="text-slate-500">Zaman / Periyot</dt><dd>{{ $p['interval'] ?? '15m' }} / {{ $p['period'] ?? 14 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Eşikler</dt><dd>{{ $p['oversold'] ?? 30 }} / {{ $p['overbought'] ?? 70 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">İşlem tutarı</dt><dd>{{ kb_money($tradeBot->order_size) }}</dd></div>
                    @elseif ($tradeBot->strategy === 'ma_cross')
                        <div class="flex justify-between"><dt class="text-slate-500">Zaman dilimi</dt><dd>{{ $p['interval'] ?? '15m' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">MA</dt><dd>{{ strtoupper($p['ma_type'] ?? 'ema') }} {{ $p['short'] ?? 9 }}/{{ $p['long'] ?? 21 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">İşlem tutarı</dt><dd>{{ kb_money($tradeBot->order_size) }}</dd></div>
                    @endif
                    @if (($p['htf_ma'] ?? 0) > 0 && $tradeBot->strategy !== 'grid')
                        <div class="flex justify-between"><dt class="text-slate-500">Üst ZD trend filtresi</dt><dd>{{ $p['htf_interval'] ?? '4h' }} · EMA{{ $p['htf_ma'] }}</dd></div>
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
            {{-- Fiyat grafiği (mum kapanışları) + grid kademeleri --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h2 class="font-semibold">Fiyat Grafiği <span class="text-xs font-normal text-slate-400">{{ $tradeBot->symbol }}</span></h2>
                    <div class="flex items-center gap-2">
                        @if ($tradeBot->strategy === 'grid')
                            <span class="text-xs text-slate-400">— <span class="text-emerald-600 font-medium">━ alış</span> · <span class="text-red-500">┄ satış</span></span>
                        @endif
                        <select id="kb-chart-interval" class="rounded-md border-slate-300 text-xs py-1">
                            @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                                <option value="{{ $iv }}" @selected($iv === '1h')>{{ $iv }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div style="height: 300px;"><canvas id="kb-price-chart"></canvas></div>
                <p id="kb-chart-empty" class="hidden text-sm text-slate-400 text-center py-10">Grafik verisi alınamadı.</p>
            </div>

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
