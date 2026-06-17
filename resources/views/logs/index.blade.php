@extends('layouts.app')
@section('title', 'Bot Günlüğü')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Bot Günlüğü</h1>
        <a href="{{ route('trades.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← İşlemler</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($logs as $log)
                <li class="px-5 py-3 flex gap-3">
                    <span class="text-xs px-2 py-0.5 rounded h-fit {{ $log->level === 'error' ? 'bg-red-100 text-red-700' : ($log->level === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">{{ $log->level }}</span>
                    <div class="flex-1">
                        <div class="flex justify-between gap-3">
                            <span class="font-medium">{{ $log->coin?->symbol ?? '—' }} · {{ $log->event }}</span>
                            <span class="text-xs text-slate-400 whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</span>
                        </div>
                        <div class="text-slate-600">{{ $log->message }}</div>
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-slate-400">Henüz log kaydı yok.</li>
            @endforelse
        </ul>
        <div class="px-5 py-3 border-t border-slate-100">{{ $logs->links() }}</div>
    </div>
@endsection
