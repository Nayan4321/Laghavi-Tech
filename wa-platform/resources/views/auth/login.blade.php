@extends('layouts.guest')
@section('title', 'Log in')
@section('content')
<form method="POST" action="/login" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input name="email" type="email" value="{{ old('email') }}" required autofocus
               class="w-full rounded-lg border-slate-300 border px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input name="password" type="password" required
               class="w-full rounded-lg border-slate-300 border px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    @error('email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="remember" class="rounded border-slate-300"> Remember me
    </label>
    <button class="w-full rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white py-2 font-medium">Log in</button>
</form>
@endsection
