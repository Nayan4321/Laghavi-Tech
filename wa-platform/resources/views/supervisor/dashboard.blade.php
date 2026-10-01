@extends('layouts.app')
@section('title','Dashboard')
@section('heading','Monitoring dashboard')
@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @php($cards = [
        ['Numbers',$stats['numbers'],'bg-slate-700'],
        ['Agents',$stats['agents'],'bg-slate-700'],
        ['Open chats',$stats['open'],'bg-emerald-600'],
        ['Unreviewed flags',$stats['unreviewed_flags'],'bg-red-600'],
        ['Messages today',$stats['messages_today'],'bg-blue-600'],
        ['Inbound today',$stats['inbound_today'],'bg-indigo-600'],
        ['Outbound today',$stats['outbound_today'],'bg-teal-600'],
    ])
    @foreach($cards as [$label,$val,$color])
        <div class="rounded-xl p-4 text-white {{ $color }} shadow">
            <div class="text-3xl font-bold">{{ $val }}</div>
            <div class="text-xs opacity-90 mt-1">{{ $label }}</div>
        </div>
    @endforeach
</div>

<div class="bg-white rounded-xl shadow">
    <div class="px-4 py-3 border-b flex items-center justify-between">
        <h2 class="font-semibold">Latest unreviewed flags</h2>
        <a href="{{ route('supervisor.flags') }}" class="text-sm text-emerald-600">View all →</a>
    </div>
    <table class="w-full text-sm">
        <thead class="text-left text-slate-500 border-b">
            <tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Agent</th><th class="px-4 py-2">Rule</th><th class="px-4 py-2">Matched</th><th class="px-4 py-2">Severity</th><th></th></tr>
        </thead>
        <tbody class="divide-y">
        @forelse($recentFlags as $flag)
            <tr>
                <td class="px-4 py-2 text-slate-500">{{ $flag->created_at->diffForHumans() }}</td>
                <td class="px-4 py-2">{{ $flag->message->sender->name ?? '—' }}</td>
                <td class="px-4 py-2">{{ $flag->rule }}</td>
                <td class="px-4 py-2"><span class="bg-yellow-100 px-1.5 rounded">{{ $flag->matched }}</span></td>
                <td class="px-4 py-2">
                    <span class="text-xs px-2 py-0.5 rounded-full {{ ['low'=>'bg-slate-200','medium'=>'bg-amber-200','high'=>'bg-red-200'][$flag->severity] ?? 'bg-slate-200' }}">{{ $flag->severity }}</span>
                </td>
                <td class="px-4 py-2 text-right"><a class="text-emerald-600" href="{{ route('supervisor.conversation',$flag->message->conversation_id) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No flags 🎉</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
