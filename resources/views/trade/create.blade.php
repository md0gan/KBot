@extends('layouts.app')
@section('title', 'Trade Botu Ekle')

@section('content')
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Trade Botu Ekle</h1>
            <a href="{{ route('trade.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Trade</a>
        </div>

        <form method="POST" action="{{ route('trade.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf
            @include('trade._form')
            <div class="mt-6 flex justify-end gap-2">
                <a href="{{ route('trade.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 hover:bg-slate-50">Vazgeç</a>
                <button class="px-4 py-2 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Kaydet</button>
            </div>
        </form>
    </div>
@endsection
