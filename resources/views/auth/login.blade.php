@extends('layouts.guest')
@section('title', 'Giriş')

@section('content')
    <h1 class="text-xl font-semibold mb-6">Giriş yap</h1>
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm text-slate-300 mb-1">E-posta</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <div>
            <label class="block text-sm text-slate-300 mb-1">Şifre</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="remember" class="rounded bg-slate-900 border-slate-700"> Beni hatırla
        </label>
        <button class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 py-2.5 font-semibold">Giriş yap</button>
    </form>
    <p class="mt-6 text-sm text-slate-400 text-center">
        Hesabın yok mu? <a href="{{ route('register') }}" class="text-sky-400 hover:underline">Kayıt ol</a>
    </p>
@endsection
