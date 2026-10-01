@extends('layouts.app')
@section('title','Users')
@section('heading','Users')
@section('actions')<a href="{{ route('settings.users.create') }}" class="text-sm px-3 py-1.5 rounded bg-emerald-500 text-white">+ Add user</a>@endsection
@section('content')
<div class="bg-white rounded-xl shadow overflow-x-auto">
<table class="w-full text-sm"><thead class="text-left text-slate-500 border-b"><tr>
    <th class="px-4 py-2">Name</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Role</th>
    <th class="px-4 py-2">Numbers</th><th class="px-4 py-2">Active</th><th></th>
</tr></thead><tbody class="divide-y">
@foreach($users as $u)
    <tr>
        <td class="px-4 py-2 font-medium">{{ $u->name }}</td>
        <td class="px-4 py-2">{{ $u->email }}</td>
        <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded-full {{ ['owner'=>'bg-purple-200','supervisor'=>'bg-blue-200','agent'=>'bg-slate-200'][$u->role] }}">{{ $u->role }}</span></td>
        <td class="px-4 py-2">{{ $u->numbers_count }}</td>
        <td class="px-4 py-2">{!! $u->is_active ? '<span class="text-emerald-600">●</span>' : '<span class="text-slate-300">●</span>' !!}</td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="{{ route('settings.users.edit',$u) }}" class="text-emerald-600 mr-2">Edit</a>
            <form method="POST" action="{{ route('settings.users.destroy',$u) }}" class="inline" onsubmit="return confirm('Delete user?')">@csrf @method('DELETE')<button class="text-red-500">Delete</button></form>
        </td>
    </tr>
@endforeach
</tbody></table></div>
@endsection
