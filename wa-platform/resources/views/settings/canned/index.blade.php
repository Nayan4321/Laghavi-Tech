@extends('layouts.app')
@section('title','Quick replies')
@section('heading','Quick replies')
@section('actions')<a href="{{ route('settings.canned.create') }}" class="text-sm px-3 py-1.5 rounded bg-emerald-500 text-white">+ Add</a>@endsection
@section('content')
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm"><thead class="text-left text-slate-500 border-b"><tr>
    <th class="px-4 py-2">Title</th><th class="px-4 py-2">Shortcut</th><th class="px-4 py-2">Body</th><th class="px-4 py-2">Number</th><th></th>
</tr></thead><tbody class="divide-y">
@forelse($replies as $r)
    <tr>
        <td class="px-4 py-2 font-medium">{{ $r->title }}</td>
        <td class="px-4 py-2"><code class="text-xs">{{ $r->shortcut }}</code></td>
        <td class="px-4 py-2 max-w-sm truncate text-slate-600">{{ $r->body }}</td>
        <td class="px-4 py-2">{{ $r->number->label ?? 'All' }}</td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="{{ route('settings.canned.edit',$r) }}" class="text-emerald-600 mr-2">Edit</a>
            <form method="POST" action="{{ route('settings.canned.destroy',$r) }}" class="inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="text-red-500">Delete</button></form>
        </td>
    </tr>
@empty<tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No quick replies yet.</td></tr>@endforelse
</tbody></table></div>
@endsection
