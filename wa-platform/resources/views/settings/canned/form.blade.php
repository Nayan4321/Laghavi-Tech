@extends('layouts.app')
@section('title', $cannedReply->exists ? 'Edit quick reply' : 'Add quick reply')
@section('heading', $cannedReply->exists ? 'Edit quick reply' : 'Add quick reply')
@section('content')
<form method="POST" action="{{ $cannedReply->exists ? route('settings.canned.update',$cannedReply) : route('settings.canned.store') }}" class="bg-white rounded-xl shadow p-5 space-y-4 max-w-2xl">
    @csrf @if($cannedReply->exists) @method('PUT') @endif
    <div class="grid md:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1">Title *</label>
            <input name="title" value="{{ old('title',$cannedReply->title) }}" required class="w-full border rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium mb-1">Shortcut</label>
            <input name="shortcut" value="{{ old('shortcut',$cannedReply->shortcut) }}" class="w-full border rounded-lg px-3 py-2" placeholder="/hours"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1">Body *</label>
        <textarea name="body" rows="4" required class="w-full border rounded-lg px-3 py-2">{{ old('body',$cannedReply->body) }}</textarea></div>
    <div><label class="block text-sm font-medium mb-1">Number (optional)</label>
        <select name="whatsapp_number_id" class="w-full border rounded-lg px-3 py-2"><option value="">All numbers</option>
            @foreach($numbers as $n)<option value="{{ $n->id }}" @selected(old('whatsapp_number_id',$cannedReply->whatsapp_number_id)==$n->id)>{{ $n->label }}</option>@endforeach
        </select></div>
    <div class="flex gap-2"><button class="px-4 py-2 rounded-lg bg-emerald-500 text-white">Save</button><a href="{{ route('settings.canned.index') }}" class="px-4 py-2 rounded-lg border">Cancel</a></div>
</form>
@endsection
