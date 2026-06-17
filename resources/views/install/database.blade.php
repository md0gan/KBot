@extends('install.layout')

@section('content')
    <h2 class="text-lg font-semibold mb-1">Veritabanı ve Uygulama</h2>
    <p class="text-sm text-slate-400 mb-5">MySQL bilgilerini girin. Belirtilen veritabanı yoksa (yetkiniz varsa) otomatik oluşturulur.</p>

    <form method="POST" action="{{ route('install.database.save') }}" class="space-y-4">
        @csrf

        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm text-slate-300 mb-1">Uygulama Adı</label>
                <input type="text" name="app_name" value="{{ old('app_name', 'KBot Binance TR') }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm text-slate-300 mb-1">Uygulama Adresi (APP_URL)</label>
                <input type="url" name="app_url" value="{{ old('app_url', request()->getSchemeAndHttpHost()) }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>

            <div>
                <label class="block text-sm text-slate-300 mb-1">DB Sunucu</label>
                <input type="text" name="db_host" value="{{ old('db_host', '127.0.0.1') }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">DB Port</label>
                <input type="number" name="db_port" value="{{ old('db_port', '3306') }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">Veritabanı Adı</label>
                <input type="text" name="db_database" value="{{ old('db_database', 'kbot') }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">Kullanıcı Adı</label>
                <input type="text" name="db_username" value="{{ old('db_username', 'root') }}" required
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm text-slate-300 mb-1">Şifre</label>
                <input type="password" name="db_password" autocomplete="off"
                       class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            </div>
        </div>

        <div class="flex justify-between pt-2">
            <a href="{{ route('install.index') }}" class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 hover:bg-slate-700">← Geri</a>
            <button class="px-5 py-2.5 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Bağlan ve devam →</button>
        </div>
    </form>
@endsection
