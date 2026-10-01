@extends('layouts.app')
@section('title','Agents')
@section('heading','Agent performance (last 7 days)')
@section('content')
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm">
    <thead class="text-left text-slate-500 border-b">
        <tr>
            <th class="px-4 py-2">Agent</th><th class="px-4 py-2">Status</th>
            <th class="px-4 py-2">Sent (7d)</th><th class="px-4 py-2">Assigned</th>
            <th class="px-4 py-2">Open</th><th class="px-4 py-2">Avg response</th>
            <th class="px-4 py-2">Flags (7d)</th><th class="px-4 py-2">Last active</th>
        </tr>
    </thead>
    <tbody class="divide-y">
    @forelse($agents as $a)
        <tr>
            <td class="px-4 py-2"><div class="font-medium">{{ $a['name'] }}</div><div class="text-xs text-slate-400">{{ $a['email'] }}</div></td>
            <td class="px-4 py-2">@if($a['active'])<span class="text-emerald-600 text-xs">● active</span>@else<span class="text-slate-400 text-xs">● off</span>@endif</td>
            <td class="px-4 py-2">{{ $a['sent_7d'] }}</td>
            <td class="px-4 py-2">{{ $a['assigned'] }}</td>
            <td class="px-4 py-2">{{ $a['open_assigned'] }}</td>
            <td class="px-4 py-2">@if($a['avg_response_secs'] !== null){{ gmdate($a['avg_response_secs'] >= 3600 ? 'H\h i\m' : 'i\m s\s', $a['avg_response_secs']) }}@else—@endif</td>
            <td class="px-4 py-2">@if($a['flags_7d'])<span class="text-red-600 font-medium">{{ $a['flags_7d'] }}</span>@else{{ $a['flags_7d'] }}@endif</td>
            <td class="px-4 py-2 text-slate-500 text-xs">{{ $a['last_active'] ? \Illuminate\Support\Carbon::parse($a['last_active'])->diffForHumans() : 'never' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="px-4 py-6 text-center text-slate-400">No agents yet. Add them under Users.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
