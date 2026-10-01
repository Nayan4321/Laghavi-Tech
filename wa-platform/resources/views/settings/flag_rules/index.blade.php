@extends('layouts.app')
@section('title','Flag rules')
@section('heading','Monitoring keyword rules')
@section('actions')<a href="{{ route('settings.flag-rules.create') }}" class="text-sm px-3 py-1.5 rounded bg-emerald-500 text-white">+ Add rule</a>@endsection
@section('content')
<p class="text-sm text-slate-500 mb-4">Messages containing any of a rule's keywords get flagged for supervisor review. Use this to catch profanity, agents sharing personal numbers, unauthorised discounts, etc.</p>
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm"><thead class="text-left text-slate-500 border-b"><tr>
    <th class="px-4 py-2">Name</th><th class="px-4 py-2">Keywords</th><th class="px-4 py-2">Applies to</th>
    <th class="px-4 py-2">Severity</th><th class="px-4 py-2">Active</th><th></th>
</tr></thead><tbody class="divide-y">
@forelse($rules as $r)
    <tr>
        <td class="px-4 py-2 font-medium">{{ $r->name }}</td>
        <td class="px-4 py-2 max-w-sm truncate text-slate-600">{{ $r->keywords }}</td>
        <td class="px-4 py-2">{{ $r->applies_to }}</td>
        <td class="px-4 py-2">{{ $r->severity }}</td>
        <td class="px-4 py-2">{!! $r->is_active ? '<span class="text-emerald-600">●</span>' : '<span class="text-slate-300">●</span>' !!}</td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="{{ route('settings.flag-rules.edit',$r) }}" class="text-emerald-600 mr-2">Edit</a>
            <form method="POST" action="{{ route('settings.flag-rules.destroy',$r) }}" class="inline" onsubmit="return confirm('Delete rule?')">@csrf @method('DELETE')<button class="text-red-500">Delete</button></form>
        </td>
    </tr>
@empty<tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No rules yet.</td></tr>@endforelse
</tbody></table></div>
@endsection
