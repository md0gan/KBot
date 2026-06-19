{{-- rows: ['name' => ['trades','realized','win_rate']] · label · quote --}}
@if ($rows->isEmpty())
    <div class="p-5 text-sm text-slate-500">Henüz kapanmış işlem yok.</div>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                <tr>
                    <th class="text-left font-medium px-4 py-2">{{ $label }}</th>
                    <th class="text-right font-medium px-4 py-2">K/Z ({{ $quote }})</th>
                    <th class="text-right font-medium px-4 py-2">İşlem</th>
                    <th class="text-right font-medium px-4 py-2">Kazanma</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $name => $r)
                    <tr>
                        <td class="px-4 py-2">{{ $name }}</td>
                        <td class="px-4 py-2 text-right font-medium {{ $r['realized'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $r['realized'] >= 0 ? '+' : '' }}{{ kb_money($r['realized']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $r['trades'] }}</td>
                        <td class="px-4 py-2 text-right">%{{ number_format($r['win_rate'], 1) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
