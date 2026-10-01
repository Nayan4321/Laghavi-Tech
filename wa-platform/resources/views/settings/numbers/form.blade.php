@extends('layouts.app')
@section('title', $number->exists ? 'Edit number' : 'Add number')
@section('heading', $number->exists ? 'Edit number' : 'Add number')
@section('content')
<form method="POST" action="{{ $number->exists ? route('settings.numbers.update',$number) : route('settings.numbers.store') }}" class="bg-white rounded-xl shadow p-5 space-y-4 max-w-2xl">
    @csrf @if($number->exists) @method('PUT') @endif
    <div><label class="block text-sm font-medium mb-1">Label *</label>
        <input name="label" value="{{ old('label',$number->label) }}" required class="w-full border rounded-lg px-3 py-2" placeholder="Sales - North team"></div>
    <div><label class="block text-sm font-medium mb-1">Display phone</label>
        <input name="display_phone" value="{{ old('display_phone',$number->display_phone) }}" class="w-full border rounded-lg px-3 py-2" placeholder="+91 98765 43210"></div>
    <div class="grid md:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1">phone_number_id *</label>
            <input name="phone_number_id" value="{{ old('phone_number_id',$number->phone_number_id) }}" required class="w-full border rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium mb-1">WABA id</label>
            <input name="waba_id" value="{{ old('waba_id',$number->waba_id) }}" class="w-full border rounded-lg px-3 py-2"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1">Permanent access token {{ $number->exists ? '(leave blank to keep)' : '*' }}</label>
        <input name="access_token" type="password" class="w-full border rounded-lg px-3 py-2" placeholder="EAAG...">
        <p class="text-xs text-slate-400 mt-1">Stored encrypted. Generate a System User permanent token in Meta Business settings.</p></div>
    <div><label class="block text-sm font-medium mb-1">Webhook verify token</label>
        <input name="webhook_verify_token" value="{{ old('webhook_verify_token',$number->webhook_verify_token) }}" class="w-full border rounded-lg px-3 py-2"></div>
    <div>
        <label class="block text-sm font-medium mb-1">Assigned agents</label>
        <div class="grid grid-cols-2 gap-1 max-h-48 overflow-y-auto border rounded-lg p-2">
            @forelse($agents as $a)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="agents[]" value="{{ $a->id }}" @checked(in_array($a->id,$assigned)) class="rounded">{{ $a->name }}</label>
            @empty<span class="text-xs text-slate-400">No agents yet — add them under Users.</span>@endforelse
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$number->is_active ?? true)) class="rounded"> Active</label>
    <div class="flex gap-2"><button class="px-4 py-2 rounded-lg bg-emerald-500 text-white">Save</button><a href="{{ route('settings.numbers.index') }}" class="px-4 py-2 rounded-lg border">Cancel</a></div>
</form>
@endsection
