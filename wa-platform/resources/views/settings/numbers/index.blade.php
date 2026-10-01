@extends('layouts.app')
@section('title','Numbers')
@section('heading','WhatsApp numbers')
@section('actions')<a href="{{ route('settings.numbers.create') }}" class="text-sm px-3 py-1.5 rounded bg-emerald-500 text-white">+ Add number</a>@endsection
@section('content')
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm"><thead class="text-left text-slate-500 border-b"><tr>
    <th class="px-4 py-2">Label</th><th class="px-4 py-2">Display</th><th class="px-4 py-2">phone_number_id</th>
    <th class="px-4 py-2">Agents</th><th class="px-4 py-2">Chats</th><th class="px-4 py-2">Active</th><th></th>
</tr></thead><tbody class="divide-y">
@forelse($numbers as $n)
    <tr>
        <td class="px-4 py-2 font-medium">{{ $n->label }}</td>
        <td class="px-4 py-2">{{ $n->display_phone }}</td>
        <td class="px-4 py-2"><code class="text-xs">{{ $n->phone_number_id }}</code></td>
        <td class="px-4 py-2">{{ $n->agents_count }}</td>
        <td class="px-4 py-2">{{ $n->conversations_count }}</td>
        <td class="px-4 py-2">{!! $n->is_active ? '<span class="text-emerald-600">●</span>' : '<span class="text-slate-300">●</span>' !!}</td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="{{ route('settings.numbers.edit',$n) }}" class="text-emerald-600 mr-2">Edit</a>
            <form method="POST" action="{{ route('settings.numbers.destroy',$n) }}" class="inline" onsubmit="return confirm('Remove this number?')">@csrf @method('DELETE')<button class="text-red-500">Delete</button></form>
        </td>
    </tr>
@empty<tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">No numbers yet. Add your first Cloud API number.</td></tr>@endforelse
</tbody></table></div>
@endsection
