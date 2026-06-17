@extends('layouts.app')
@section('title', 'Ayarlar')

@section('content')
    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Ayarlar</h1>

        {{-- Baglanti durumu --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-semibold">Binance TR Bağlantısı</div>
                    <div class="text-sm text-slate-500 mt-1">
                        @if ($setting->api_verified_at)
                            <span class="text-emerald-600">✓ Doğrulandı</span> · {{ $setting->api_status }} ·
                            {{ $setting->api_verified_at->format('d.m.Y H:i') }}
                        @elseif ($setting->hasApiCredentials())
                            <span class="text-amber-600">Anahtar kayıtlı ama test edilmedi.</span> {{ $setting->api_status }}
                        @else
                            <span class="text-slate-400">API anahtarı girilmedi (simülasyon çalışır).</span>
                        @endif
                    </div>
                </div>
                <form method="POST" action="{{ route('settings.test') }}">@csrf
                    <button class="px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">Bağlantıyı test et</button>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-6"
              data-old-mode="{{ $setting->trading_mode }}" onsubmit="return kbConfirmLive(this)">
            @csrf @method('PUT')

            {{-- API --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="font-semibold mb-4">API Bilgileri</h2>
                <div class="grid gap-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">API Key</label>
                        <input type="text" name="api_key" autocomplete="off"
                               placeholder="{{ $setting->hasApiCredentials() ? '•••••••• (kayıtlı — değiştirmek için yeni değer girin)' : 'Binance TR API Key' }}"
                               class="w-full rounded-lg border-slate-300 font-mono text-sm focus:border-sky-500 focus:ring-sky-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">API Secret</label>
                        <input type="password" name="api_secret" autocomplete="off"
                               placeholder="{{ $setting->hasApiCredentials() ? '•••••••• (kayıtlı)' : 'Binance TR API Secret' }}"
                               class="w-full rounded-lg border-slate-300 font-mono text-sm focus:border-sky-500 focus:ring-sky-500">
                        <p class="text-xs text-slate-400 mt-1">Anahtarlar veritabanında şifrelenerek saklanır. Boş bırakırsanız mevcut değer korunur.</p>
                    </div>
                    <div class="grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Base URL</label>
                            <input type="url" name="base_url" value="{{ $setting->base_url }}"
                                   placeholder="{{ config('bot.base_url') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Market Veri URL</label>
                            <input type="url" name="market_base_url" value="{{ $setting->market_base_url }}"
                                   placeholder="{{ config('bot.market_base_url') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                        </div>
                    </div>
                    <div class="grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">recvWindow (ms)</label>
                            <input type="number" name="recv_window" value="{{ $setting->recv_window }}" min="1000" max="60000"
                                   class="w-full rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Varsayılan Karşı Para</label>
                            <input type="text" name="default_quote" value="{{ $setting->default_quote }}"
                                   class="w-full rounded-lg border-slate-300 uppercase text-sm focus:border-sky-500 focus:ring-sky-500">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Genel --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="font-semibold mb-4">Genel</h2>
                <div class="grid gap-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Genel İşlem Modu</label>
                        <select name="trading_mode" class="w-full md:w-1/2 rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                            <option value="simulation" @selected($setting->trading_mode === 'simulation')>Simülasyon (kağıt — gerçek emir yok)</option>
                            <option value="live" @selected($setting->trading_mode === 'live')>Canlı (gerçek emir)</option>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Coinlerde modu "Genel ayarı kullan" seçili olanlar bu modu uygular.</p>
                    </div>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="bot_enabled" value="0">
                        <input type="checkbox" name="bot_enabled" value="1" @checked($setting->bot_enabled)
                               class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                        <span class="text-sm text-slate-700">Bot aktif (zamanlanmış alım ve kar-al çalışsın)</span>
                    </label>
                </div>
            </div>

            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <strong>Uyarı:</strong> Canlı modda emirler gerçek paranızla Binance TR'de uygulanır. API anahtarınıza yalnızca
                <em>spot işlem</em> izni verin; <em>para çekme</em> iznini kapatın. Stratejiyi önce simülasyonda test edin.
            </div>

            <div class="flex justify-end">
                <button class="px-5 py-2.5 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Ayarları kaydet</button>
            </div>
        </form>
    </div>

    <script>
        function kbConfirmLive(form) {
            var sel = form.querySelector('[name="trading_mode"]');
            var old = form.getAttribute('data-old-mode');
            if (sel && sel.value === 'live' && old === 'simulation') {
                return confirm('CANLI moda geçiyorsunuz.\n\nSimülasyon işlemleri ve TÜM pozisyonlar silinecek/sıfırlanacak. Bu işlem geri alınamaz.\n\nDevam edilsin mi?');
            }
            return true;
        }
    </script>
@endsection
