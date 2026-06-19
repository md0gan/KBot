@php($paginated = $paginated ?? false)
<div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-4 py-2">Tarih</th>
                <th class="text-left font-medium px-4 py-2">Sembol</th>
                <th class="text-left font-medium px-4 py-2">Strateji</th>
                <th class="text-left font-medium px-4 py-2">Yön</th>
                <th class="text-left font-medium px-4 py-2">Sebep</th>
                <th class="text-right font-medium px-4 py-2">Miktar</th>
                <th class="text-right font-medium px-4 py-2">Fiyat</th>
                <th class="text-right font-medium px-4 py-2">Tutar</th>
                <th class="text-right font-medium px-4 py-2">K/Z</th>
                <th class="text-center font-medium px-4 py-2">Mod</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($orders as $o)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 whitespace-nowrap text-slate-500">{{ $o->created_at->format('d.m.Y H:i') }}</td>
                    <td class="px-4 py-2 font-medium">
                        <a href="{{ route('trade.show', $o->trade_bot_id) }}" class="hover:text-sky-600">{{ $o->symbol }}</a>
                    </td>
                    <td class="px-4 py-2 uppercase text-xs text-slate-500">{{ $o->strategy }}</td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $o->side === 'BUY' ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-700' }}">{{ $o->side }}</span>
                    </td>
                    <td class="px-4 py-2 text-xs text-slate-500">{{ $o->reason }}</td>
                    <td class="px-4 py-2 text-right">{{ kb_qty($o->quantity) }}</td>
                    <td class="px-4 py-2 text-right">{{ kb_price($o->price) }}</td>
                    <td class="px-4 py-2 text-right">{{ kb_money($o->quote_amount) }}</td>
                    <td class="px-4 py-2 text-right {{ $o->realized_profit > 0 ? 'text-emerald-600 font-medium' : ($o->realized_profit < 0 ? 'text-red-600' : 'text-slate-400') }}">
                        {{ $o->realized_profit != 0 ? ($o->realized_profit > 0 ? '+' : '').kb_money($o->realized_profit) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs {{ $o->mode === 'live' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $o->mode === 'live' ? 'canlı' : 'sim' }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-400">Henüz trade işlemi yok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($paginated)
    <div class="px-4 py-3 border-t border-slate-100">{{ $orders->links() }}</div>
@endif
