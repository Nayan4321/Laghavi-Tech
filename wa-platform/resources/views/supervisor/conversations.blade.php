@extends('layouts.app')
@section('title','Conversations')
@section('heading','All conversations')
@section('actions')
<a href="{{ route('supervisor.export.messages', request()->query()) }}" class="text-sm px-3 py-1.5 rounded bg-slate-800 text-white">Export CSV</a>
@endsection
@section('content')
<form class="bg-white rounded-xl shadow p-3 mb-4 flex flex-wrap gap-2 items-end">
    <div><label class="block text-xs text-slate-500">Number</label>
        <select name="number_id" class="border rounded px-2 py-1.5 text-sm"><option value="">All</option>
            @foreach($numbers as $n)<option value="{{ $n->id }}" @selected(request('number_id')==$n->id)>{{ $n->label }}</option>@endforeach
        </select></div>
    <div><label class="block text-xs text-slate-500">Agent</label>
        <select name="agent_id" class="border rounded px-2 py-1.5 text-sm"><option value="">All</option>
            @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent_id')==$a->id)>{{ $a->name }}</option>@endforeach
        </select></div>
    <div><label class="block text-xs text-slate-500">Status</label>
        <select name="status" class="border rounded px-2 py-1.5 text-sm"><option value="">All</option>
            @foreach(['open','pending','resolved'] as $s)<option value="{{ $s }}" @selected(request('status')==$s)>{{ ucfirst($s) }}</option>@endforeach
        </select></div>
    <div><label class="block text-xs text-slate-500">Search</label>
        <input name="q" value="{{ request('q') }}" class="border rounded px-2 py-1.5 text-sm" placeholder="name / number"></div>
    <button class="px-3 py-1.5 rounded bg-emerald-500 text-white text-sm">Filter</button>
</form>

<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm">
    <thead class="text-left text-slate-500 border-b"><tr>
        <th class="px-4 py-2">Contact</th><th class="px-4 py-2">Number</th><th class="px-4 py-2">Agent</th>
        <th class="px-4 py-2">Status</th><th class="px-4 py-2">Last message</th><th></th>
    </tr></thead>
    <tbody class="divide-y">
    @forelse($conversations as $c)
        <tr>
            <td class="px-4 py-2 font-medium">{{ $c->contact?->displayName() }}</td>
            <td class="px-4 py-2">{{ $c->number->label ?? '' }}</td>
            <td class="px-4 py-2">{{ $c->assignedAgent->name ?? '—' }}</td>
            <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded-full {{ $c->status==='open'?'bg-emerald-100':'bg-slate-200' }}">{{ $c->status }}</span></td>
            <td class="px-4 py-2 text-slate-500 text-xs">{{ $c->last_message_at?->diffForHumans() }}</td>
            <td class="px-4 py-2 text-right"><a href="{{ route('supervisor.conversation',$c) }}" class="text-emerald-600">View →</a></td>
        </tr>
    @empty
        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No conversations</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $conversations->links() }}</div>
@endsection
