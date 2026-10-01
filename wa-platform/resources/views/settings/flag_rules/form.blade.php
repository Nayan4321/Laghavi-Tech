@extends('layouts.app')
@section('title', $flagRule->exists ? 'Edit rule' : 'Add rule')
@section('heading', $flagRule->exists ? 'Edit rule' : 'Add rule')
@section('content')
<form method="POST" action="{{ $flagRule->exists ? route('settings.flag-rules.update',$flagRule) : route('settings.flag-rules.store') }}" class="bg-white rounded-xl shadow p-5 space-y-4 max-w-2xl">
    @csrf @if($flagRule->exists) @method('PUT') @endif
    <div><label class="block text-sm font-medium mb-1">Rule name *</label>
        <input name="name" value="{{ old('name',$flagRule->name) }}" required class="w-full border rounded-lg px-3 py-2" placeholder="Sharing personal number"></div>
    <div><label class="block text-sm font-medium mb-1">Keywords / phrases (comma-separated) *</label>
        <textarea name="keywords" rows="3" required class="w-full border rounded-lg px-3 py-2" placeholder="my number, whatsapp me on, call me on, discount, free">{{ old('keywords',$flagRule->keywords) }}</textarea></div>
    <div class="grid md:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1">Applies to</label>
            <select name="applies_to" class="w-full border rounded-lg px-3 py-2">
                @foreach(['out'=>'Agent messages (outbound)','in'=>'Customer messages (inbound)','both'=>'Both'] as $v=>$l)
                    <option value="{{ $v }}" @selected(old('applies_to',$flagRule->applies_to)==$v)>{{ $l }}</option>@endforeach
            </select></div>
        <div><label class="block text-sm font-medium mb-1">Severity</label>
            <select name="severity" class="w-full border rounded-lg px-3 py-2">
                @foreach(['low','medium','high'] as $s)<option value="{{ $s }}" @selected(old('severity',$flagRule->severity)==$s)>{{ ucfirst($s) }}</option>@endforeach
            </select></div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$flagRule->is_active ?? true)) class="rounded"> Active</label>
    <div class="flex gap-2"><button class="px-4 py-2 rounded-lg bg-emerald-500 text-white">Save</button><a href="{{ route('settings.flag-rules.index') }}" class="px-4 py-2 rounded-lg border">Cancel</a></div>
</form>
@endsection
