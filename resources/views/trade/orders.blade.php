@extends('layouts.app')
@section('title', 'Trade İşlemleri')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Trade İşlem Geçmişi</h1>
        <a href="{{ route('trade.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Trade</a>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Bot</label>
            <select name="bot" class="rounded-lg border-slate-300 text-sm">
                <option value="">Tümü</option>
                @foreach ($bots as $b)
                    <option value="{{ $b->id }}" @selected(request('bot') == $b->id)>{{ $b->symbol }} ({{ $b->strategy }})</option>
                @endforeach
            </select>
        </div>
        <button class="px-4 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">Filtrele</button>
        @if (request('bot'))
            <a href="{{ route('trade.orders') }}" class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Temizle</a>
        @endif
    </form>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @include('trade._orders_table', ['orders' => $orders, 'paginated' => true])
    </div>
@endsection
