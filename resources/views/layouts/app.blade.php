<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'KBot') · Binance TR Bot</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { DEFAULT: '#0ea5e9', dark: '#0369a1' } } } } }
    </script>
</head>
<body class="h-full bg-slate-100 text-slate-800">
@php($setting = auth()->user()?->settings())
<div class="min-h-full">
    <nav class="bg-slate-900 text-slate-100">
        <div class="mx-auto max-w-7xl px-4">
            <div class="flex h-14 items-center justify-between">
                <div class="flex items-center gap-6">
                    <a href="{{ route('dashboard') }}" class="font-bold text-lg tracking-tight">
                        <span class="text-brand">K</span>Bot
                    </a>
                    @php($nav = ['dashboard' => 'Panel', 'coins.index' => 'Coinler', 'trade.index' => 'Trade', 'trades.index' => 'İşlemler', 'logs.index' => 'Loglar', 'settings.edit' => 'Ayarlar', 'account.edit' => 'Hesap'])
                    <div class="hidden md:flex items-center gap-1 text-sm">
                        @foreach ($nav as $route => $label)
                            <a href="{{ route($route) }}"
                               class="px-3 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs($route) || request()->routeIs(str_replace('.index','.*', $route)) ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    @if ($setting)
                        <span class="hidden sm:inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold
                            {{ $setting->trading_mode === 'live' ? 'bg-emerald-500/25 text-emerald-200 ring-1 ring-emerald-400/50' : 'bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-500/40' }}">
                            {{ $setting->trading_mode === 'live' ? '● CANLI' : '○ SİMÜLASYON' }}
                        </span>
                    @endif
                    <span class="hidden sm:inline text-slate-400">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="px-3 py-1.5 rounded-md bg-slate-800 hover:bg-slate-700">Çıkış</button>
                    </form>
                    <button type="button" aria-label="Menü"
                            onclick="document.getElementById('mobile-menu').classList.toggle('hidden')"
                            class="md:hidden p-2 -mr-1 rounded-md hover:bg-slate-800">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobil menü (hamburger ile açılır) --}}
            <div id="mobile-menu" class="hidden md:hidden border-t border-slate-800 py-2 space-y-1">
                @foreach ($nav as $route => $label)
                    <a href="{{ route($route) }}"
                       class="block px-3 py-2 rounded-md text-sm {{ request()->routeIs($route) || request()->routeIs(str_replace('.index', '.*', $route)) ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    @if ($setting && $setting->trading_mode === 'live')
        <div class="bg-emerald-600 text-white text-center text-sm py-1.5 px-4">
            ⚠ CANLI MOD AKTİF — emirler gerçek paranızla Binance TR'de uygulanır.
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-6">
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-8 text-center text-xs text-slate-400">
        KBot · Binance TR DCA &amp; Kar-Al Botu — Yatırım tavsiyesi değildir. Riskler size aittir.
    </footer>
</div>
</body>
</html>
