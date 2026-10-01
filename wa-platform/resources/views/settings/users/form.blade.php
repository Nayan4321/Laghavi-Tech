@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')
@section('heading', $user->exists ? 'Edit user' : 'Add user')
@section('content')
<form method="POST" action="{{ $user->exists ? route('settings.users.update',$user) : route('settings.users.store') }}" class="bg-white rounded-xl shadow p-5 space-y-4 max-w-2xl">
    @csrf @if($user->exists) @method('PUT') @endif
    <div class="grid md:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1">Name *</label>
            <input name="name" value="{{ old('name',$user->name) }}" required class="w-full border rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium mb-1">Email *</label>
            <input name="email" type="email" value="{{ old('email',$user->email) }}" required class="w-full border rounded-lg px-3 py-2"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1">Role *</label>
        <select name="role" class="w-full border rounded-lg px-3 py-2">
            @foreach(['agent'=>'Agent (inbox only)','supervisor'=>'Supervisor (monitor all)','owner'=>'Owner (full access)'] as $v=>$lbl)
                <option value="{{ $v }}" @selected(old('role',$user->role)==$v)>{{ $lbl }}</option>
            @endforeach
        </select></div>
    <div class="grid md:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1">Password {{ $user->exists ? '(blank = keep)' : '*' }}</label>
            <input name="password" type="password" class="w-full border rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium mb-1">Confirm password</label>
            <input name="password_confirmation" type="password" class="w-full border rounded-lg px-3 py-2"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1">Numbers this agent can use</label>
        <div class="grid grid-cols-2 gap-1 max-h-48 overflow-y-auto border rounded-lg p-2">
            @forelse($numbers as $n)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="numbers[]" value="{{ $n->id }}" @checked(in_array($n->id,$assigned)) class="rounded">{{ $n->label }}</label>
            @empty<span class="text-xs text-slate-400">No numbers yet.</span>@endforelse
        </div>
        <p class="text-xs text-slate-400 mt-1">Only applies to agents. Supervisors & owners see all numbers.</p></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$user->is_active ?? true)) class="rounded"> Active</label>
    <div class="flex gap-2"><button class="px-4 py-2 rounded-lg bg-emerald-500 text-white">Save</button><a href="{{ route('settings.users.index') }}" class="px-4 py-2 rounded-lg border">Cancel</a></div>
</form>
@endsection
