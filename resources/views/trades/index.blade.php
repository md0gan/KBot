@extends('layouts.app')
@section('title', 'İşlemler')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">İşlem Geçmişi</h1>
        <a href="{{ route('logs.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Bot günlüğü →</a>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Coin</label>
            <select name="coin" class="rounded-lg border-slate-300 text-sm">
                <option value="">Tümü</option>
                @foreach ($coins as $c)
                    <option value="{{ $c->id }}" @selected(request('coin') == $c->id)>{{ $c->symbol }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Tür</label>
            <select name="kind" class="rounded-lg border-slate-300 text-sm">
                <option value="">Tümü</option>
                <option value="dca_buy" @selected(request('kind') === 'dca_buy')>Düzenli Alım</option>
                <option value="manual_buy" @selected(request('kind') === 'manual_buy')>Manuel Alım</option>
                <option value="profit_take" @selected(request('kind') === 'profit_take')>Kar-Al</option>
                <option value="manual_sell" @selected(request('kind') === 'manual_sell')>Manuel Satış</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Mod</label>
            <select name="mode" class="rounded-lg border-slate-300 text-sm">
                <option value="">Tümü</option>
                <option value="simulation" @selected(request('mode') === 'simulation')>Simülasyon</option>
                <option value="live" @selected(request('mode') === 'live')>Canlı</option>
            </select>
        </div>
        <button class="px-4 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">Filtrele</button>
        @if (request()->hasAny(['coin','kind','mode']))
            <a href="{{ route('trades.index') }}" class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Temizle</a>
        @endif
    </form>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @include('trades._table', ['trades' => $trades, 'paginated' => true])
    </div>
@endsection
