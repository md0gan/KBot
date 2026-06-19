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
            @if ($tradeBot->tag)
                <a href="{{ route('trade.index', ['tag' => $tradeBot->tag]) }}" class="text-xs px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 border border-sky-100 hover:bg-sky-100">{{ $tradeBot->tag }}</a>
            @endif
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var canvas = document.getElementById('kb-price-chart');
            if (!canvas) return;
            var sel = document.getElementById('kb-chart-interval');
            var empty = document.getElementById('kb-chart-empty');
            var base = "{{ route('trade.candles', $tradeBot) }}";
            var KEY = 'kb_chart_iv_{{ $tradeBot->id }}';
            var data = null;
            try { var sv = localStorage.getItem(KEY); if (sv) sel.value = sv; } catch (e) {}

            function fmtP(v) {
                if (v >= 1000) return v.toLocaleString('tr-TR', { maximumFractionDigits: 0 });
                if (v >= 1) return v.toLocaleString('tr-TR', { maximumFractionDigits: 2 });
                return v.toLocaleString('tr-TR', { maximumFractionDigits: 6 });
            }
            function showEmpty(on) {
                canvas.style.display = on ? 'none' : 'block';
                if (empty) empty.classList.toggle('hidden', !on);
            }

            function draw() {
                if (!data || !data.points || !data.points.length) return;
                var pts = data.points, grid = data.grid || [];
                var dpr = window.devicePixelRatio || 1;
                var cssW = canvas.clientWidth || 600, cssH = canvas.clientHeight || 300;
                canvas.width = Math.round(cssW * dpr);
                canvas.height = Math.round(cssH * dpr);
                var ctx = canvas.getContext('2d');
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.clearRect(0, 0, cssW, cssH);
                ctx.font = '10px sans-serif';

                var padR = 58, padL = 4, padT = 8, padB = 6, gap = 8;
                var innerH = cssH - padT - padB;
                var volH = Math.round(innerH * 0.18);
                var priceH = innerH - volH - gap;
                var priceTop = padT, priceBot = padT + priceH;
                var volBot = priceBot + gap + volH;
                var plotW = cssW - padL - padR;

                var lo = Infinity, hi = -Infinity;
                pts.forEach(function (p) { if (p.l < lo) lo = p.l; if (p.h > hi) hi = p.h; });
                grid.forEach(function (g) {
                    [g.buy, g.sell].forEach(function (v) { if (v > 0) { if (v < lo) lo = v; if (v > hi) hi = v; } });
                });
                if (!isFinite(lo)) lo = 0;
                if (!isFinite(hi) || hi <= lo) hi = lo + 1;
                var pd = (hi - lo) * 0.04 || 1; lo -= pd; hi += pd;
                function py(price) { return priceTop + (hi - price) / (hi - lo) * priceH; }

                var vmax = 0; pts.forEach(function (p) { if (p.v > vmax) vmax = p.v; });
                if (vmax <= 0) vmax = 1;

                var n = pts.length, slot = plotW / n;
                var bodyW = Math.max(1, Math.min(slot * 0.7, 14));
                function px(i) { return padL + slot * (i + 0.5); }

                // yatay fiyat ızgarası + sağ etiketler
                ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
                for (var t = 0; t <= 4; t++) {
                    var gp = lo + (hi - lo) * t / 4, gy = py(gp);
                    ctx.strokeStyle = 'rgba(148,163,184,0.18)'; ctx.lineWidth = 1; ctx.setLineDash([]);
                    ctx.beginPath(); ctx.moveTo(padL, gy); ctx.lineTo(padL + plotW, gy); ctx.stroke();
                    ctx.fillStyle = '#94a3b8'; ctx.fillText(fmtP(gp), padL + plotW + 4, gy);
                }

                // grid kademe çizgileri (alış yeşil / satış kırmızı)
                grid.forEach(function (g) {
                    if (g.buy > 0) {
                        var y = py(g.buy);
                        ctx.strokeStyle = g.status === 'holding' ? 'rgba(16,185,129,0.9)' : 'rgba(16,185,129,0.55)';
                        ctx.setLineDash(g.status === 'holding' ? [] : [5, 3]); ctx.lineWidth = 1;
                        ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + plotW, y); ctx.stroke();
                    }
                    if (g.sell > 0) {
                        var ys = py(g.sell);
                        ctx.strokeStyle = 'rgba(239,68,68,0.5)'; ctx.setLineDash([4, 4]); ctx.lineWidth = 1;
                        ctx.beginPath(); ctx.moveTo(padL, ys); ctx.lineTo(padL + plotW, ys); ctx.stroke();
                    }
                });
                ctx.setLineDash([]);

                // hacim çubukları
                pts.forEach(function (p, i) {
                    var up = p.c >= p.o, h = (p.v / vmax) * volH;
                    ctx.fillStyle = up ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)';
                    ctx.fillRect(px(i) - bodyW / 2, volBot - h, bodyW, h);
                });

                // mumlar (yeşil ↑ / kırmızı ↓)
                pts.forEach(function (p, i) {
                    var x = px(i), up = p.c >= p.o, col = up ? '#10b981' : '#ef4444';
                    ctx.strokeStyle = col; ctx.fillStyle = col; ctx.lineWidth = 1;
                    ctx.beginPath(); ctx.moveTo(x, py(p.h)); ctx.lineTo(x, py(p.l)); ctx.stroke();
                    var yo = py(p.o), yc = py(p.c), top = Math.min(yo, yc), bh = Math.max(1, Math.abs(yc - yo));
                    ctx.fillRect(x - bodyW / 2, top, bodyW, bh);
                });

                // son fiyat çizgisi + etiket
                var last = pts[n - 1].c, ly = py(last);
                ctx.strokeStyle = 'rgba(2,132,199,0.6)'; ctx.setLineDash([2, 2]); ctx.lineWidth = 1;
                ctx.beginPath(); ctx.moveTo(padL, ly); ctx.lineTo(padL + plotW, ly); ctx.stroke(); ctx.setLineDash([]);
                ctx.fillStyle = '#0284c7'; ctx.fillRect(padL + plotW, ly - 8, padR - 4, 16);
                ctx.fillStyle = '#fff'; ctx.fillText(fmtP(last), padL + plotW + 4, ly);
            }

            function load() {
                fetch(base + '?interval=' + encodeURIComponent(sel.value), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.points || !d.points.length) { showEmpty(true); return; }
                        showEmpty(false); data = d; draw();
                    })
                    .catch(function () { showEmpty(true); });
            }
            sel.addEventListener('change', function () { try { localStorage.setItem(KEY, sel.value); } catch (e) {} load(); });
            window.addEventListener('resize', function () { if (data) draw(); });
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
                    @elseif ($tradeBot->strategy === 'smart_scalp')
                        <div class="flex justify-between"><dt class="text-slate-500">Zaman / Kâr hedefi</dt><dd>{{ $p['interval'] ?? '5m' }} / %{{ $p['scalp_tp_pct'] ?? '0.6' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">RSI giriş/çıkış</dt><dd>{{ $p['oversold'] ?? 30 }} / {{ $p['overbought'] ?? 60 }} (p{{ $p['rsi_period'] ?? 14 }})</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Bollinger</dt><dd>p{{ $p['bb_period'] ?? 20 }} · k{{ $p['bb_k'] ?? 2 }}</dd></div>
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
                        <span class="text-xs text-slate-400">mum <span class="text-emerald-600">▲</span>/<span class="text-red-500">▼</span> + hacim
                            @if ($tradeBot->strategy === 'grid')
                                · kademe <span class="text-emerald-600">alış</span>/<span class="text-red-500">satış</span>
                            @endif
                        </span>
                        <select id="kb-chart-interval" class="rounded-md border-slate-300 text-xs py-1">
                            @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                                <option value="{{ $iv }}" @selected($iv === '1h')>{{ $iv }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div style="height: 320px;"><canvas id="kb-price-chart" style="width:100%;height:100%;display:block"></canvas></div>
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
