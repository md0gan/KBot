@extends('install.layout')

@section('content')
    <h2 class="text-lg font-semibold mb-4">Sunucu Gereksinimleri</h2>

    @php
        $row = fn ($name, $ok) =>
            '<li class="flex items-center justify-between py-1.5 border-b border-slate-700/60">'
            .'<span class="text-slate-300">'.e($name).'</span>'
            .'<span class="'.($ok ? 'text-emerald-400' : 'text-red-400').' font-semibold">'.($ok ? '✓' : '✗').'</span></li>';
    @endphp

    <div class="space-y-5 text-sm">
        <div>
            <div class="text-slate-400 uppercase text-xs tracking-wide mb-1">PHP</div>
            <ul>
                @foreach ($php as $name => $ok)
                    {!! $row($name, $ok) !!}
                @endforeach
                {!! $row('APP_KEY tanımlı', $appKey) !!}
            </ul>
            @unless ($appKey)
                <p class="text-xs text-amber-400 mt-1">APP_KEY yok. Sunucuda <code>php artisan key:generate</code> çalıştırın (kurulum scripti bunu otomatik yapar).</p>
            @endunless
        </div>

        <div>
            <div class="text-slate-400 uppercase text-xs tracking-wide mb-1">PHP Eklentileri</div>
            <ul class="grid grid-cols-2 gap-x-6">
                @foreach ($ext as $name => $ok)
                    {!! $row($name, $ok) !!}
                @endforeach
            </ul>
        </div>

        <div>
            <div class="text-slate-400 uppercase text-xs tracking-wide mb-1">Yazılabilir Dizinler</div>
            <ul>
                @foreach ($writable as $name => $ok)
                    {!! $row($name, $ok) !!}
                @endforeach
            </ul>
        </div>
    </div>

    <div class="mt-6 flex justify-end">
        @if ($canProceed)
            <a href="{{ route('install.database') }}"
               class="px-5 py-2.5 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Devam →</a>
        @else
            <a href="{{ route('install.index') }}"
               class="px-5 py-2.5 rounded-lg bg-slate-700 text-slate-200 font-semibold hover:bg-slate-600">↻ Eksikleri giderdim, yenile</a>
        @endif
    </div>
@endsection
