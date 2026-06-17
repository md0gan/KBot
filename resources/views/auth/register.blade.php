@extends('layouts.guest')
@section('title', 'Kayıt')

@section('content')
    <h1 class="text-xl font-semibold mb-6">Hesap oluştur</h1>
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm text-slate-300 mb-1">Ad Soyad</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">E-posta</label>
            <input type="email" name="email" value="{{ old('email') }}" required
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">Şifre</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">Şifre (tekrar)</label>
            <input type="password" name="password_confirmation" required
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <button class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 py-2.5 font-semibold">Kayıt ol</button>
    </form>
    <p class="mt-6 text-sm text-slate-400 text-center">
        Zaten hesabın var mı? <a href="{{ route('login') }}" class="text-sky-400 hover:underline">Giriş yap</a>
    </p>
@endsection
