@php($paginated = $paginated ?? false)
<div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-4 py-2">Tarih</th>
                <th class="text-left font-medium px-4 py-2">Sembol</th>
                <th class="text-left font-medium px-4 py-2">Tür</th>
                <th class="text-left font-medium px-4 py-2">Yön</th>
                <th class="text-right font-medium px-4 py-2">Miktar</th>
                <th class="text-right font-medium px-4 py-2">Fiyat</th>
                <th class="text-right font-medium px-4 py-2">Tutar</th>
                <th class="text-right font-medium px-4 py-2">Kar</th>
                <th class="text-center font-medium px-4 py-2">Mod</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($trades as $t)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 whitespace-nowrap text-slate-500">{{ $t->created_at->format('d.m.Y H:i') }}</td>
                    <td class="px-4 py-2 font-medium">
                        <a href="{{ route('coins.show', $t->coin_id) }}" class="hover:text-sky-600">{{ $t->symbol }}</a>
                    </td>
                    <td class="px-4 py-2">{{ $t->kindLabel() }}</td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $t->side === 'BUY' ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-700' }}">{{ $t->side }}</span>
                    </td>
                    <td class="px-4 py-2 text-right">{{ kb_qty($t->quantity) }}</td>
                    <td class="px-4 py-2 text-right">{{ kb_price($t->price) }}</td>
                    <td class="px-4 py-2 text-right">{{ kb_money($t->quote_amount) }}</td>
                    <td class="px-4 py-2 text-right {{ $t->realized_profit > 0 ? 'text-emerald-600 font-medium' : 'text-slate-400' }}">
                        {{ $t->realized_profit > 0 ? '+'.kb_money($t->realized_profit) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs {{ $t->mode === 'live' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                            {{ $t->mode === 'live' ? 'canlı' : 'sim' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">Henüz işlem yok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($paginated)
    <div class="px-4 py-3 border-t border-slate-100">{{ $trades->links() }}</div>
@endif
