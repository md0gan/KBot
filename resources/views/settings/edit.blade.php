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

        {{-- Uygulama (ortak) Telegram botu — yalnizca yonetici --}}
        @if (auth()->user()->isAdmin())
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
                <h2 class="font-semibold mb-1">Uygulama Telegram Botu <span class="text-xs font-normal text-slate-400">(yönetici)</span></h2>
                <p class="text-sm text-slate-500 mb-4">
                    Tüm kullanıcıların <strong>tek tıkla</strong> bağlanacağı ortak bot. Telegram'da <strong>@BotFather</strong> ile bir bot oluşturup
                    token'ı buraya girin. Her kullanıcının bildirimleri yine yalnızca kendi sohbetine gider.
                </p>

                @if ($tgAppConfigured)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-emerald-50 border border-emerald-100 px-4 py-3 mb-4">
                        <div class="text-sm text-emerald-800">✓ Ayarlı: <strong>{{ '@'.$tgAppUsername }}</strong></div>
                        <form method="POST" action="{{ route('settings.telegram-app') }}"
                              onsubmit="return confirm('Ortak bot kaldırılsın mı? Kullanıcıların bağlantıları çalışmayı durdurur.');">@csrf
                            <input type="hidden" name="clear_app" value="1">
                            <button class="text-sm text-red-600 hover:text-red-700">Kaldır</button>
                        </form>
                    </div>
                @endif

                <form method="POST" action="{{ route('settings.telegram-app') }}" class="flex flex-wrap gap-3 items-end">@csrf
                    <div class="flex-1 min-w-[16rem]">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Bot Token</label>
                        <input type="password" name="app_bot_token" autocomplete="off"
                               placeholder="{{ $tgAppConfigured ? '•••••••• (değiştirmek için yeni token girin)' : 'BotFather token' }}"
                               class="w-full rounded-lg border-slate-300 font-mono text-sm focus:border-sky-500 focus:ring-sky-500">
                    </div>
                    <button class="px-4 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-700">Doğrula &amp; kaydet</button>
                </form>
            </div>
        @endif

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

            {{-- Telegram bildirimleri --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="font-semibold mb-1">Telegram Bildirimleri</h2>
                <p class="text-sm text-slate-500 mb-4">Bildirimler hesabınıza bağlı Telegram sohbetine gider — yalnızca size.</p>

                <label class="flex items-center gap-2 mb-4">
                    <input type="hidden" name="telegram_enabled" value="0">
                    <input type="checkbox" name="telegram_enabled" value="1" @checked($setting->telegram_enabled)
                           class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                    <span class="text-sm text-slate-700">Telegram bildirimleri aktif</span>
                </label>

                {{-- Baglanti durumu / tek tikla bagla --}}
                <div class="rounded-lg border px-4 py-3 mb-5 {{ $setting->isTelegramConnected() ? 'bg-emerald-50 border-emerald-100' : 'bg-slate-50 border-slate-200' }}">
                    @if ($setting->isTelegramConnected())
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm text-emerald-800">
                                ✓ <strong>Bağlı</strong> · sohbet <code>{{ $setting->telegram_chat_id }}</code>
                                @if ($setting->telegram_connected_at)<span class="text-emerald-600">· {{ $setting->telegram_connected_at->format('d.m.Y H:i') }}</span>@endif
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" form="tg-test-form" class="text-sm px-3 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-700">Test mesajı</button>
                                <button type="submit" form="tg-disconnect-form" class="text-sm px-3 py-1.5 rounded-lg border border-red-300 text-red-600 hover:bg-red-50">Bağlantıyı kaldır</button>
                            </div>
                        </div>
                        <p class="text-xs text-emerald-700 mt-2">İpucu: Telegram'dan <code>/salter</code> yazarak tüm otomatik işlemleri durdurabilir/başlatabilirsiniz (şalter); <code>/durum</code> ile durumu görürsünüz.</p>
                    @elseif (session('tg_connect_url'))
                        <div class="text-sm text-slate-700 mb-3">Telegram'ı açıp <strong>Başlat</strong>'a basın. Bağlantı birkaç saniye içinde otomatik tamamlanır.</div>
                        <div class="flex flex-wrap items-center gap-3">
                            <a href="{{ session('tg_connect_url') }}" target="_blank" rel="noopener"
                               class="px-4 py-2 text-sm rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Telegram'da Aç →</a>
                            <span id="tg-wait" class="text-sm text-slate-500">Bağlanması bekleniyor…</span>
                        </div>
                    @elseif ($tgAppConfigured)
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm text-slate-600">Henüz bağlı değil. Tek tıkla bağlanın:</div>
                            <button type="submit" form="tg-connect-form" class="px-4 py-2 text-sm rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Telegram'ı Bağla</button>
                        </div>
                    @else
                        <div class="text-sm text-slate-600">
                            Ortak Telegram botu henüz ayarlanmadı. Yönetici botu tanımlayınca buradan tek tıkla bağlanabilirsiniz —
                            ya da aşağıdaki <strong>Gelişmiş</strong> bölümünden kendi botunuzu girebilirsiniz.
                        </div>
                    @endif
                </div>

                {{-- Bildirim turleri --}}
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="tg_notify_trades" value="0">
                        <input type="checkbox" name="tg_notify_trades" value="1" @checked($setting->tg_notify_trades) class="rounded border-slate-300 text-sky-600">
                        <span class="text-sm text-slate-700">İşlemler (alım/kar-al/satış)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="tg_notify_errors" value="0">
                        <input type="checkbox" name="tg_notify_errors" value="1" @checked($setting->tg_notify_errors) class="rounded border-slate-300 text-sky-600">
                        <span class="text-sm text-slate-700">Hatalar</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="tg_notify_balance" value="0">
                        <input type="checkbox" name="tg_notify_balance" value="1" @checked($setting->tg_notify_balance) class="rounded border-slate-300 text-sky-600">
                        <span class="text-sm text-slate-700">Bakiye azalması / düşük bakiye</span>
                    </label>
                </div>

                <div class="mt-5">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Düşük bakiye eşiği ({{ $setting->default_quote }}) — opsiyonel</label>
                    <input type="number" name="low_balance_threshold" step="0.01" min="0" value="{{ $setting->low_balance_threshold }}"
                           class="w-full md:w-1/2 rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                    <p class="text-xs text-slate-400 mt-1">Canlı kote bakiye bu değerin altına düşerse uyarı gönderilir. (Yalnızca canlı modda.)</p>
                </div>

                {{-- Gelismis: kendi botunu kullan --}}
                <details class="mt-5 border-t border-slate-100 pt-4" @if (filled($setting->telegram_bot_token)) open @endif>
                    <summary class="text-sm font-medium text-slate-600 cursor-pointer">Gelişmiş: kendi botumu kullan</summary>
                    <div class="grid gap-5 mt-4">
                        <p class="text-xs text-slate-400">Ortak bot yerine kendi botunuzu kullanmak isterseniz token ve chat ID girin. Token doluysa bildirimler bu botla gönderilir.</p>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Bot Token</label>
                            <input type="password" name="telegram_bot_token" autocomplete="off"
                                   placeholder="{{ filled($setting->telegram_bot_token) ? '•••••••• (kayıtlı)' : 'BotFather token' }}"
                                   class="w-full rounded-lg border-slate-300 font-mono text-sm focus:border-sky-500 focus:ring-sky-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Chat ID</label>
                            <input type="text" name="telegram_chat_id" value="{{ $setting->telegram_chat_id }}"
                                   class="w-full rounded-lg border-slate-300 text-sm focus:border-sky-500 focus:ring-sky-500">
                            <p class="text-xs text-slate-400 mt-1">Bota bir mesaj yazın; <strong>@userinfobot</strong> ile chat ID'nizi öğrenin. Kaydedip "Test mesajı" ile deneyin.</p>
                        </div>
                    </div>
                </details>
            </div>

            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <strong>Uyarı:</strong> Canlı modda emirler gerçek paranızla Binance TR'de uygulanır. API anahtarınıza yalnızca
                <em>spot işlem</em> izni verin; <em>para çekme</em> iznini kapatın. Stratejiyi önce simülasyonda test edin.
            </div>

            <div class="flex justify-end">
                <button class="px-5 py-2.5 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Ayarları kaydet</button>
            </div>
        </form>

        {{-- Telegram yardimci formlari (karttaki butonlar bunlari tetikler) --}}
        <form id="tg-test-form" method="POST" action="{{ route('settings.telegram-test') }}" class="hidden">@csrf</form>
        <form id="tg-connect-form" method="POST" action="{{ route('settings.telegram-connect') }}" class="hidden">@csrf</form>
        <form id="tg-disconnect-form" method="POST" action="{{ route('settings.telegram-disconnect') }}" class="hidden">@csrf</form>
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

        // "Telegram'da Aç" gosterildiyse, baglanmayi canli olarak bekle.
        (function () {
            var wait = document.getElementById('tg-wait');
            if (!wait) return;
            var url = "{{ route('settings.telegram-status') }}";
            var tries = 0, max = 45; // ~2.5 dk
            var timer = setInterval(function () {
                tries++;
                fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.connected) {
                            clearInterval(timer);
                            wait.textContent = '✓ Bağlandı! Sayfa yenileniyor…';
                            wait.className = 'text-sm text-emerald-600 font-medium';
                            setTimeout(function () { window.location.reload(); }, 900);
                        } else if (tries >= max) {
                            clearInterval(timer);
                            wait.textContent = 'Hâlâ bağlanmadı. "Başlat"a bastığınızdan emin olun, sonra sayfayı yenileyin.';
                        }
                    })
                    .catch(function () {});
            }, 3500);
        })();
    </script>
@endsection
