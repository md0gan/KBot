@php($coin = $coin ?? null)
@php($quoteDefault = old('quote_asset', $coin->quote_asset ?? (auth()->user()->settings()->default_quote ?? 'TRY')))
<div class="grid md:grid-cols-2 gap-5">
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-slate-700 mb-1">İsim / Etiket (opsiyonel)</label>
        <input type="text" name="name" maxlength="60" value="{{ old('name', $coin->name ?? '') }}"
               class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500" placeholder="örn. BTC 2.5x uzun vade">
        <p class="text-xs text-slate-400 mt-1">Aynı parite (örn. BTC_TRY) farklı çarpanlarla birden çok kez eklenebilir; ayırt etmek için isim verin.</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Coin (Base Asset)</label>
        <input type="text" name="base_asset" required placeholder="BTC"
               value="{{ old('base_asset', $coin->base_asset ?? '') }}"
               class="w-full rounded-lg border-slate-300 uppercase focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Örn. BTC, ETH, AVAX</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Karşı Para (Quote)</label>
        <input type="text" name="quote_asset" required
               value="{{ $quoteDefault }}"
               class="w-full rounded-lg border-slate-300 uppercase focus:border-sky-500 focus:ring-sky-500">
        <p class="text-xs text-slate-400 mt-1">Binance TR'de genelde <strong>TRY</strong> (örn. BTC_TRY). Bazı paritelerde USDT olabilir.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Her Periyotta Alınacak Tutar</label>
        <div class="flex">
            <input type="number" name="buy_amount" step="0.00000001" min="0" required
                   value="{{ old('buy_amount', $coin->buy_amount ?? config('bot.default_buy_amount', 10)) }}"
                   class="w-full rounded-l-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 text-slate-500 text-sm">{{ $quoteDefault }}</span>
        </div>
        <p class="text-xs text-slate-400 mt-1">Örn. her hafta 200 TRY (min. işlem tutarının üzerinde olmalı)</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Alım Periyodu</label>
        <select name="interval" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            @foreach (config('bot.intervals') as $k => $label)
                <option value="{{ $k }}" @selected(old('interval', $coin->interval ?? 'weekly') === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-400 mt-1">Coin eklenince hemen ilk alım yapılır; sonra her periyotta (örn. haftalık → +1 hafta) tekrarlanır.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">İşlem Modu</label>
        <select name="mode" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            @foreach (config('bot.modes') as $k => $label)
                <option value="{{ $k }}" @selected(old('mode', $coin->mode ?? 'inherit') === $k)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Kar-Al Çarpanı</label>
        <div class="flex">
            <input type="number" name="profit_multiplier" step="0.01" min="1.01" required
                   value="{{ old('profit_multiplier', $coin->profit_multiplier ?? config('bot.default_profit_multiplier', 2)) }}"
                   class="w-full rounded-l-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 text-slate-500 text-sm">x</span>
        </div>
        <p class="text-xs text-slate-400 mt-1">Değer ≥ çarpan × sermaye olunca kar-al tetiklenir (örn. 2x).</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Kar-Al Stratejisi</label>
        <select name="take_profit_strategy" id="tp_strategy" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500"
                onchange="document.getElementById('sell_ratio_wrap').style.display = this.value === 'fixed_ratio' ? 'block' : 'none'">
            @foreach (config('bot.take_profit_strategies') as $k => $label)
                <option value="{{ $k }}" @selected(old('take_profit_strategy', $coin->take_profit_strategy ?? 'leave_capital') === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <div id="sell_ratio_wrap" class="mt-2" style="display: {{ old('take_profit_strategy', $coin->take_profit_strategy ?? 'leave_capital') === 'fixed_ratio' ? 'block' : 'none' }}">
            <label class="block text-xs font-medium text-slate-600 mb-1">Karın satılacak oranı (0.01 - 1.00)</label>
            <input type="number" name="sell_ratio" step="0.01" min="0.01" max="1"
                   value="{{ old('sell_ratio', $coin->sell_ratio ?? 0.5) }}"
                   class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
        </div>
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-slate-700 mb-1">Maksimum Alım Fiyatı (opsiyonel)</label>
        <div class="flex md:w-1/2">
            <input type="number" name="max_buy_price" step="0.00000001" min="0"
                   value="{{ old('max_buy_price', $coin->max_buy_price ?? '') }}"
                   class="w-full rounded-l-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
            <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 text-slate-500 text-sm">{{ $quoteDefault }}</span>
        </div>
        <p class="text-xs text-slate-400 mt-1">Doldurulursa: güncel fiyat bu değerin <strong>üzerindeyse</strong> zamanlanmış alım yapılmaz (dip beklenir). Boş = filtre yok. Manuel "Al" bunu yok sayar.</p>
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-slate-700 mb-1">Not (opsiyonel)</label>
        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">{{ old('notes', $coin->notes ?? '') }}</textarea>
    </div>

    <div class="md:col-span-2">
        <label class="flex items-center gap-2">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $coin->enabled ?? true))
                   class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
            <span class="text-sm text-slate-700">Aktif (zamanlanmış alımlar çalışsın)</span>
        </label>
    </div>
</div>
