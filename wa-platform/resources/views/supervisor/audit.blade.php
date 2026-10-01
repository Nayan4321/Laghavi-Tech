@extends('layouts.app')
@section('title','Audit log')
@section('heading','Audit log')
@section('content')
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm">
    <thead class="text-left text-slate-500 border-b"><tr>
        <th class="px-4 py-2">When</th><th class="px-4 py-2">User</th><th class="px-4 py-2">Action</th>
        <th class="px-4 py-2">Subject</th><th class="px-4 py-2">IP</th>
    </tr></thead>
    <tbody class="divide-y">
    @forelse($logs as $log)
        <tr>
            <td class="px-4 py-2 text-xs text-slate-500">{{ $log->created_at?->format('d M, H:i:s') }}</td>
            <td class="px-4 py-2">{{ $log->user->name ?? 'system' }}</td>
            <td class="px-4 py-2"><code class="text-xs">{{ $log->action }}</code></td>
            <td class="px-4 py-2 text-xs text-slate-500">{{ class_basename($log->subject_type) }} {{ $log->subject_id }}</td>
            <td class="px-4 py-2 text-xs text-slate-400">{{ $log->ip }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No activity yet</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
