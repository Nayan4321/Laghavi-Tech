@extends('layouts.app')
@section('title','Flags')
@section('heading','Flagged messages')
@section('content')
<form class="mb-4 flex gap-2 text-sm">
    <select name="severity" class="border rounded px-2 py-1.5"><option value="">All severity</option>
        @foreach(['low','medium','high'] as $s)<option value="{{ $s }}" @selected(request('severity')==$s)>{{ ucfirst($s) }}</option>@endforeach
    </select>
    <select name="state" class="border rounded px-2 py-1.5"><option value="">All</option>
        <option value="open" @selected(request('state')=='open')>Unreviewed only</option></select>
    <button class="px-3 py-1.5 rounded bg-emerald-500 text-white">Filter</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm">
    <thead class="text-left text-slate-500 border-b"><tr>
        <th class="px-4 py-2">When</th><th class="px-4 py-2">Agent</th><th class="px-4 py-2">Contact</th>
        <th class="px-4 py-2">Rule</th><th class="px-4 py-2">Message</th><th class="px-4 py-2">Severity</th><th></th>
    </tr></thead>
    <tbody class="divide-y">
    @forelse($flags as $f)
        <tr class="{{ $f->reviewed?'opacity-50':'' }}">
            <td class="px-4 py-2 text-xs text-slate-500">{{ $f->created_at->diffForHumans() }}</td>
            <td class="px-4 py-2">{{ $f->message->sender->name ?? '—' }}</td>
            <td class="px-4 py-2">{{ $f->message->conversation->contact?->displayName() }}</td>
            <td class="px-4 py-2">{{ $f->rule }}</td>
            <td class="px-4 py-2 max-w-xs truncate">{{ \Illuminate\Support\Str::limit($f->message->body, 60) }}</td>
            <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded-full {{ ['low'=>'bg-slate-200','medium'=>'bg-amber-200','high'=>'bg-red-200'][$f->severity] ?? 'bg-slate-200' }}">{{ $f->severity }}</span></td>
            <td class="px-4 py-2 text-right whitespace-nowrap">
                <a href="{{ route('supervisor.conversation',$f->message->conversation_id) }}" class="text-emerald-600 mr-2">Open</a>
                @unless($f->reviewed)
                <form method="POST" action="{{ route('supervisor.flags.review',$f) }}" class="inline">@csrf<button class="text-slate-500">Mark reviewed</button></form>
                @endunless
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">No flags</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $flags->links() }}</div>
@endsection
