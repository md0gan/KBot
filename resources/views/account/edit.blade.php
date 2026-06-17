@extends('layouts.app')
@section('title', 'Hesap')

@section('content')
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Hesap</h1>

        {{-- Profil --}}
        <form method="POST" action="{{ route('account.profile') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            @csrf @method('PUT')
            <h2 class="font-semibold mb-4">Profil Bilgileri</h2>
            <div class="grid gap-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Ad Soyad</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                           class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">E-posta</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                           class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                </div>
            </div>
            <div class="mt-5 flex justify-end">
                <button class="px-4 py-2 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-500">Kaydet</button>
            </div>
        </form>

        {{-- Sifre --}}
        <form method="POST" action="{{ route('account.password') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf @method('PUT')
            <h2 class="font-semibold mb-4">Şifre Değiştir</h2>
            <div class="grid gap-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Mevcut Şifre</label>
                    <input type="password" name="current_password" required autocomplete="current-password"
                           class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Yeni Şifre</label>
                    <input type="password" name="password" required autocomplete="new-password"
                           class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                    <p class="text-xs text-slate-400 mt-1">En az 8 karakter.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Yeni Şifre (tekrar)</label>
                    <input type="password" name="password_confirmation" required autocomplete="new-password"
                           class="w-full rounded-lg border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                </div>
            </div>
            <div class="mt-5 flex justify-end">
                <button class="px-4 py-2 rounded-lg bg-slate-900 text-white font-semibold hover:bg-slate-700">Şifreyi değiştir</button>
            </div>
        </form>
    </div>
@endsection
