<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Giriş') · KBot</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-slate-900 text-slate-100">
<div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
    <div class="mb-6 text-center">
        <div class="text-3xl font-bold"><span class="text-sky-400">K</span>Bot</div>
        <div class="text-slate-400 text-sm mt-1">Binance TR · DCA &amp; Kar-Al Botu</div>
    </div>
    <div class="w-full max-w-md bg-slate-800 rounded-2xl shadow-xl p-8 ring-1 ring-slate-700">
        @include('partials.flash')
        @yield('content')
    </div>
    <p class="mt-6 text-xs text-slate-500 max-w-md text-center">
        Yatırım tavsiyesi değildir. Kripto işlemleri yüksek risk içerir; tüm sorumluluk kullanıcıya aittir.
    </p>
</div>
</body>
</html>
