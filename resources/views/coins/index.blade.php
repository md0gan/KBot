@extends('layouts.app')
@section('title', 'Coinler')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Coinler</h1>
        <a href="{{ route('coins.create') }}" class="px-3 py-2 text-sm rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">+ Coin ekle</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left font-medium px-4 py-2">Sembol</th>
                        <th class="text-left font-medium px-4 py-2">Durum</th>
                        <th class="text-right font-medium px-4 py-2">Tutar</th>
                        <th class="text-left font-medium px-4 py-2">Periyot</th>
                        <th class="text-right font-medium px-4 py-2">Çarpan</th>
                        <th class="text-right font-medium px-4 py-2">Sermaye</th>
                        <th class="text-right font-medium px-4 py-2">Değer</th>
                        <th class="text-right font-medium px-4 py-2">Çevrilen Kar</th>
                        <th class="text-left font-medium px-4 py-2">Sonraki alım</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($coins as $coin)
                        @php($pos = $coin->position)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-semibold"><a href="{{ route('coins.show', $coin) }}" class="hover:text-sky-600">{{ $coin->symbol }}</a></td>
                            <td class="px-4 py-2">
                                @if ($coin->enabled)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">aktif</span>
                                @else
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">durdu</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">{{ kb_money($coin->buy_amount) }}</td>
                            <td class="px-4 py-2">{{ config('bot.intervals')[$coin->interval] ?? $coin->interval }}</td>
                            <td class="px-4 py-2 text-right">{{ kb_mult($coin->profit_multiplier) }}x</td>
                            <td class="px-4 py-2 text-right">{{ kb_money($pos->cost_basis ?? 0) }}</td>
                            <td class="px-4 py-2 text-right">{{ kb_money($pos->last_value ?? ($pos->cost_basis ?? 0)) }}</td>
                            <td class="px-4 py-2 text-right text-emerald-600">+{{ kb_money($pos->realized_profit ?? 0) }}</td>
                            <td class="px-4 py-2 text-slate-500">{{ $coin->next_buy_at?->format('d.m H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('coins.toggle', $coin) }}" class="inline">@csrf
                                    <button class="text-xs px-2 py-1 rounded border border-slate-300 hover:bg-slate-100">{{ $coin->enabled ? 'Durdur' : 'Başlat' }}</button>
                                </form>
                                <a href="{{ route('coins.edit', $coin) }}" class="text-xs px-2 py-1 rounded border border-slate-300 hover:bg-slate-100">Düzenle</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-8 text-center text-slate-400">Henüz coin yok. <a href="{{ route('coins.create') }}" class="text-sky-600 hover:underline">Ekleyin</a>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
