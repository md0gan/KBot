<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurulum · KBot</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-slate-900 text-slate-100">
@php($step = $step ?? 1)
<div class="min-h-full flex flex-col items-center py-12 px-4">
    <div class="text-center mb-8">
        <div class="text-3xl font-bold"><span class="text-sky-400">K</span>Bot</div>
        <div class="text-slate-400 text-sm mt-1">Kurulum Sihirbazı</div>
    </div>

    {{-- Adım göstergesi --}}
    <div class="flex items-center gap-3 mb-8">
        @foreach (['Gereksinimler', 'Veritabanı', 'Yönetici'] as $i => $label)
            @php($n = $i + 1)
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold
                    {{ $n < $step ? 'bg-emerald-500 text-white' : ($n === $step ? 'bg-sky-500 text-white' : 'bg-slate-700 text-slate-400') }}">
                    {{ $n < $step ? '✓' : $n }}
                </div>
                <span class="text-sm {{ $n === $step ? 'text-white' : 'text-slate-400' }}">{{ $label }}</span>
            </div>
            @if (! $loop->last)
                <div class="w-6 h-px bg-slate-700"></div>
            @endif
        @endforeach
    </div>

    <div class="w-full max-w-xl bg-slate-800 rounded-2xl shadow-xl p-8 ring-1 ring-slate-700">
        @include('partials.flash')
        @yield('content')
    </div>

    <p class="mt-6 text-xs text-slate-500 max-w-xl text-center">
        Kurulum tamamlandıktan sonra bu sayfa otomatik olarak kapanır. · Yatırım tavsiyesi değildir.
    </p>
</div>
</body>
</html>
