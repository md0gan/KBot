@extends('layouts.app')
@section('title', 'Trade Botu Düzenle')

@section('content')
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">{{ $tradeBot->symbol }} · Düzenle</h1>
            <a href="{{ route('trade.show', $tradeBot) }}" class="text-sm text-slate-500 hover:text-slate-700">← Detay</a>
        </div>

        <form method="POST" action="{{ route('trade.update', $tradeBot) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf @method('PUT')
            @include('trade._form', ['bot' => $tradeBot])
            <div class="mt-6 flex justify-between">
                <button form="delete-trade-bot" class="px-4 py-2 rounded-lg border border-red-300 text-red-600 hover:bg-red-50">Sil</button>
                <div class="flex gap-2">
                    <a href="{{ route('trade.show', $tradeBot) }}" class="px-4 py-2 rounded-lg border border-slate-300 hover:bg-slate-50">Vazgeç</a>
                    <button class="px-4 py-2 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Güncelle</button>
                </div>
            </div>
        </form>

        <form id="delete-trade-bot" method="POST" action="{{ route('trade.destroy', $tradeBot) }}"
              onsubmit="return confirm('{{ $tradeBot->symbol }} trade botu silinsin mi? Tüm trade geçmişi de silinir.');">
            @csrf @method('DELETE')
        </form>
    </div>
@endsection
