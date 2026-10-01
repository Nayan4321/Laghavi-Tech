@extends('layouts.guest')
@section('title', 'Setup')
@section('content')
<p class="text-sm text-slate-600 mb-4">Create the <strong>owner</strong> account. This runs the database setup and locks itself afterwards.</p>
<form method="POST" action="/install" class="space-y-4">
    @csrf
    @error('database')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    <div>
        <label class="block text-sm font-medium mb-1">Your name</label>
        <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input name="email" type="email" value="{{ old('email') }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        @error('email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input name="password" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        @error('password')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Confirm password</label>
        <input name="password_confirmation" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <button class="w-full rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white py-2 font-medium">Run setup</button>
</form>
@endsection
