@extends('install.layout')

@section('content')
    <h2 class="text-lg font-semibold mb-1">Yönetici Hesabı</h2>
    <p class="text-sm text-slate-400 mb-5">İlk yönetici (admin) hesabını oluşturun. Bu bilgilerle giriş yapacaksınız.</p>

    <form method="POST" action="{{ route('install.admin.save') }}" class="space-y-4">
        @csrf

        <div>
            <label class="block text-sm text-slate-300 mb-1">Ad Soyad</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">E-posta</label>
            <input type="email" name="email" value="{{ old('email') }}" required
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">Şifre</label>
            <input type="password" name="password" required autocomplete="new-password"
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
            <p class="text-xs text-slate-500 mt-1">En az 8 karakter.</p>
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">Şifre (tekrar)</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 focus:border-sky-500 focus:ring-sky-500">
        </div>

        <div class="flex justify-end pt-2">
            <button class="px-5 py-2.5 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-500">Kurulumu tamamla ✓</button>
        </div>
    </form>
@endsection
