@php($bot = $bot ?? null)
@php($p = $bot?->params ?? [])
@php($quoteDefault = old('quote_asset', $bot->quote_asset ?? (auth()->user()->settings()->default_quote ?? 'TRY')))
@php($curStrategy = old('strategy', $bot->strategy ?? 'grid'))

<div class="grid md:grid-cols-2 gap-5">
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-slate-700 mb-1">İsim (opsiyonel)</label>
        <input type="text" name="name" value="{{ old('name', $bot->name ?? '') }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500" placeholder="örn. BTC Grid">
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Coin (Base)</label>
        <input type="text" name="base_asset" required placeholder="BTC"
               value="{{ old('base_asset', $bot->base_asset ?? '') }}"
               class="w-full rounded-lg border-slate-300 uppercase focus:border-sky-500 focus:ring-sky-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Karşı Para (Quote)</label>
        <input type="text" name="quote_asset" required value="{{ $quoteDefault }}"
               class="w-full rounded-lg border-slate-300 uppercase focus:border-sky-500 focus:ring-sky-500">
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Strateji</label>
        <select name="strategy" id="strategy-select" onchange="kbUpdateStrategy()"
                class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <option value="grid" @selected($curStrategy === 'grid')>Grid</option>
            <option value="rsi" @selected($curStrategy === 'rsi')>RSI (aşırı alım/satım)</option>
            <option value="ma_cross" @selected($curStrategy === 'ma_cross')>MA Kesişimi (SMA/EMA)</option>
            <option value="macd" @selected($curStrategy === 'macd')>MACD (sinyal kesişimi)</option>
            <option value="bollinger" @selected($curStrategy === 'bollinger')>Bollinger Bantları</option>
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">İşlem Modu</label>
        <select name="mode" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            @foreach (config('bot.modes') as $k => $label)
                <option value="{{ $k }}" @selected(old('mode', $bot->mode ?? 'inherit') === $k)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Bütçe ({{ $quoteDefault }})</label>
        <input type="number" name="budget" step="0.00000001" min="0" required
               value="{{ old('budget', $bot->budget ?? 0) }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Bu bota ayrılan toplam tutar.</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">İşlem Başına Tutar (opsiyonel)</label>
        <input type="number" name="order_size" step="0.00000001" min="0"
               value="{{ old('order_size', $bot->order_size ?? '') }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">RSI/MA: her sinyalde alınacak tutar (boşsa bütçe). Grid'de bütçe kademelere bölünür.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Maksimum Alım Fiyatı (opsiyonel)</label>
        <input type="number" name="max_buy_price" step="0.00000001" min="0"
               value="{{ old('max_buy_price', $bot->max_buy_price ?? '') }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Fiyat bunun üzerindeyse alım yapılmaz. Boş = filtre yok.</p>
    </div>
    <div class="flex items-end">
        <label class="flex items-center gap-2">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $bot->enabled ?? true))
                   class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
            <span class="text-sm text-slate-700">Aktif</span>
        </label>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Zarar Durdurma (% — 0 = kapalı)</label>
        <input type="number" name="max_loss_pct" step="0.1" min="0" max="100" value="{{ old('max_loss_pct', $p['max_loss_pct'] ?? 0) }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Toplam K/Z bütçenin bu yüzdesi kadar zarara ulaşınca bot otomatik durur (örn. 50).</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Trailing Take-Profit (% — 0 = kapalı)</label>
        <input type="number" name="trail_tp_pct" step="0.1" min="0" max="100" value="{{ old('trail_tp_pct', $p['trail_tp_pct'] ?? 0) }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Toplam K/Z zirveden bütçenin bu yüzdesi kadar geri çekilince pozisyon kapatılır, kâr bankaya yazılır (örn. 5).</p>
    </div>
    <div class="flex items-center">
        <label class="flex items-center gap-2">
            <input type="hidden" name="compounding" value="0">
            <input type="checkbox" name="compounding" value="1" @checked(old('compounding', $p['compounding'] ?? false))
                   class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
            <span class="text-sm text-slate-700">Kârı bütçeye ekle (compounding)</span>
        </label>
    </div>
</div>

{{-- Grid parametreleri --}}
<div class="strategy-params mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" data-strategy="grid">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Grid Ayarları</h3>
    <div class="grid md:grid-cols-2 gap-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Aralık Modu</label>
            <select name="range_mode" id="grid-range-mode" onchange="kbUpdateGridRange()"
                    class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                <option value="manual" @selected(old('range_mode', $p['range_mode'] ?? 'manual') === 'manual')>Manuel (alt/üst fiyat)</option>
                <option value="auto" @selected(old('range_mode', $p['range_mode'] ?? 'manual') === 'auto')>Otomatik (kademe adımı %)</option>
                <option value="atr" @selected(old('range_mode', $p['range_mode'] ?? 'manual') === 'atr')>ATR (volatiliteye göre dinamik)</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Kademe Sayısı</label>
            <input type="number" name="levels" min="2" max="100" value="{{ old('levels', $p['levels'] ?? 5) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div class="grid-manual">
            <label class="block text-sm font-medium text-slate-700 mb-1">Alt Fiyat</label>
            <input type="number" name="lower" step="0.00000001" min="0" value="{{ old('lower', $p['lower'] ?? '') }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div class="grid-manual">
            <label class="block text-sm font-medium text-slate-700 mb-1">Üst Fiyat</label>
            <input type="number" name="upper" step="0.00000001" min="0" value="{{ old('upper', $p['upper'] ?? '') }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div class="grid-auto">
            <label class="block text-sm font-medium text-slate-700 mb-1">Kademe Adımı (%)</label>
            <input type="number" name="percent" step="0.1" min="0.1" max="90" value="{{ old('percent', $p['percent'] ?? 10) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <p class="text-xs text-slate-400 mt-1">Her kademe arası ve alış→satış farkı bu orandır. Örn. %5: her alış bir öncekinin %5 altında, satış alışın %5 üstünde.</p>
        </div>
        <div class="grid-auto grid-atr">
            <label class="block text-sm font-medium text-slate-700 mb-1">Başlangıç Noktası</label>
            <select name="anchor" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                <option value="below" @selected(old('anchor', $p['anchor'] ?? 'below') === 'below')>Güncel fiyatın altına (alım merdiveni)</option>
                <option value="symmetric" @selected(old('anchor', $p['anchor'] ?? 'below') === 'symmetric')>Simetrik (±)</option>
            </select>
            <p class="text-xs text-slate-400 mt-1">"Alım merdiveni": tüm kademeler güncel fiyatın altında; bot yalnızca düştükçe alır.</p>
        </div>
        <div class="grid-atr">
            <label class="block text-sm font-medium text-slate-700 mb-1">ATR Zaman Dilimi</label>
            <select name="atr_interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                @foreach (['15m','30m','1h','4h','1d'] as $iv)
                    <option value="{{ $iv }}" @selected(old('atr_interval', $p['atr_interval'] ?? '1h') === $iv)>{{ $iv }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid-atr">
            <label class="block text-sm font-medium text-slate-700 mb-1">ATR Periyot</label>
            <input type="number" name="atr_period" min="2" max="100" value="{{ old('atr_period', $p['atr_period'] ?? 14) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div class="grid-atr md:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">ATR Çarpanı</label>
            <input type="number" name="atr_mult" step="0.1" min="0.1" max="20" value="{{ old('atr_mult', $p['atr_mult'] ?? 1) }}"
                   class="w-full md:w-1/2 rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <p class="text-xs text-slate-400 mt-1">Kademe adımı = ATR × çarpan (fiyat birimi). Yüksek çarpan = geniş kademeler; aralık volatilite arttıkça otomatik genişler.</p>
        </div>
        <div class="md:col-span-2">
            <label class="flex items-center gap-2">
                <input type="hidden" name="trailing" value="0">
                <input type="checkbox" name="trailing" value="1" @checked(old('trailing', $p['trailing'] ?? false))
                       class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                <span class="text-sm text-slate-700">Trailing — fiyat aralık dışına çıkınca (pozisyon boşken) grid'i güncel fiyata yeniden ortala</span>
            </label>
        </div>
    </div>
</div>

{{-- RSI parametreleri --}}
<div class="strategy-params mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" data-strategy="rsi">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">RSI Ayarları</h3>
    <div class="grid md:grid-cols-4 gap-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Zaman Dilimi</label>
            <select name="interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                    <option value="{{ $iv }}" @selected(old('interval', $p['interval'] ?? '15m') === $iv)>{{ $iv }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Periyot</label>
            <input type="number" name="period" min="2" max="200" value="{{ old('period', $p['period'] ?? 14) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Aşırı Satım (al)</label>
            <input type="number" name="oversold" min="1" max="99" value="{{ old('oversold', $p['oversold'] ?? 30) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Aşırı Alım (sat)</label>
            <input type="number" name="overbought" min="1" max="99" value="{{ old('overbought', $p['overbought'] ?? 70) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
    </div>
</div>

{{-- MA kesişimi parametreleri --}}
<div class="strategy-params mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" data-strategy="ma_cross">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">MA Kesişimi Ayarları</h3>
    <div class="grid md:grid-cols-4 gap-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Zaman Dilimi</label>
            <select name="interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                    <option value="{{ $iv }}" @selected(old('interval', $p['interval'] ?? '15m') === $iv)>{{ $iv }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Tip</label>
            <select name="ma_type" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                <option value="ema" @selected(old('ma_type', $p['ma_type'] ?? 'ema') === 'ema')>EMA</option>
                <option value="sma" @selected(old('ma_type', $p['ma_type'] ?? 'ema') === 'sma')>SMA</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Kısa Periyot</label>
            <input type="number" name="short" min="1" max="400" value="{{ old('short', $p['short'] ?? 9) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Uzun Periyot</label>
            <input type="number" name="long" min="2" max="800" value="{{ old('long', $p['long'] ?? 21) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
    </div>
</div>

{{-- MACD parametreleri --}}
<div class="strategy-params mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" data-strategy="macd">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">MACD Ayarları</h3>
    <div class="grid md:grid-cols-4 gap-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Zaman Dilimi</label>
            <select name="interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                    <option value="{{ $iv }}" @selected(old('interval', $p['interval'] ?? '15m') === $iv)>{{ $iv }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Hızlı EMA</label>
            <input type="number" name="fast" min="1" max="200" value="{{ old('fast', $p['fast'] ?? 12) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Yavaş EMA</label>
            <input type="number" name="slow" min="2" max="400" value="{{ old('slow', $p['slow'] ?? 26) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Sinyal</label>
            <input type="number" name="signal" min="1" max="200" value="{{ old('signal', $p['signal'] ?? 9) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
    </div>
    <div class="grid md:grid-cols-2 gap-5 mt-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Trend MA filtresi (0 = kapalı)</label>
            <input type="number" name="trend_ma" min="0" max="400" value="{{ old('trend_ma', $p['trend_ma'] ?? 0) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <p class="text-xs text-slate-400 mt-1">Doluysa yalnızca fiyat EMA(bu periyot) üzerindeyken alır.</p>
        </div>
        <div class="flex items-center">
            <label class="flex items-center gap-2">
                <input type="hidden" name="require_above_zero" value="0">
                <input type="checkbox" name="require_above_zero" value="1" @checked(old('require_above_zero', $p['require_above_zero'] ?? false))
                       class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                <span class="text-sm text-slate-700">Sadece MACD &gt; 0 iken al (sıfır çizgisi)</span>
            </label>
        </div>
    </div>
</div>

{{-- Bollinger parametreleri --}}
<div class="strategy-params mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4" data-strategy="bollinger">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Bollinger Ayarları</h3>
    <div class="grid md:grid-cols-3 gap-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Zaman Dilimi</label>
            <select name="interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                @foreach (['1m','5m','15m','30m','1h','4h','1d'] as $iv)
                    <option value="{{ $iv }}" @selected(old('interval', $p['interval'] ?? '15m') === $iv)>{{ $iv }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Periyot</label>
            <input type="number" name="period" min="2" max="200" value="{{ old('period', $p['period'] ?? 20) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Std Çarpanı (k)</label>
            <input type="number" name="k" step="0.1" min="0.5" max="5" value="{{ old('k', $p['k'] ?? 2) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
    </div>
    <div class="grid md:grid-cols-2 gap-5 mt-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Trend MA filtresi (0 = kapalı)</label>
            <input type="number" name="trend_ma" min="0" max="400" value="{{ old('trend_ma', $p['trend_ma'] ?? 0) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <p class="text-xs text-slate-400 mt-1">Doluysa yalnızca fiyat EMA(bu periyot) üzerindeyken alır.</p>
        </div>
        <div class="flex items-center">
            <label class="flex items-center gap-2">
                <input type="hidden" name="confirm_rsi" value="0">
                <input type="checkbox" name="confirm_rsi" value="1" @checked(old('confirm_rsi', $p['confirm_rsi'] ?? false))
                       class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                <span class="text-sm text-slate-700">RSI onayı (alımda RSI ≤ 40)</span>
            </label>
        </div>
    </div>
</div>

<div class="mt-5">
    <label class="block text-sm font-medium text-slate-700 mb-1">Not (opsiyonel)</label>
    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">{{ old('notes', $bot->notes ?? '') }}</textarea>
</div>

<script>
    function kbUpdateStrategy() {
        var val = document.getElementById('strategy-select').value;
        document.querySelectorAll('.strategy-params').forEach(function (el) {
            var match = el.getAttribute('data-strategy') === val;
            el.style.display = match ? '' : 'none';
            el.querySelectorAll('input, select').forEach(function (inp) { inp.disabled = !match; });
        });
        if (val === 'grid') kbUpdateGridRange();
    }
    function kbUpdateGridRange() {
        var mode = document.getElementById('grid-range-mode').value;
        document.querySelectorAll('.grid-manual, .grid-auto, .grid-atr').forEach(function (el) {
            var show = el.classList.contains('grid-' + mode);
            el.style.display = show ? '' : 'none';
            el.querySelectorAll('input, select').forEach(function (i) { i.disabled = !show; });
        });
    }
    document.addEventListener('DOMContentLoaded', kbUpdateStrategy);
</script>
