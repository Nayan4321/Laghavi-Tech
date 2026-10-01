@extends('layouts.app')
@section('title','Transcript')
@section('heading','')
@section('content')
<div class="bg-white rounded-xl shadow mb-4 p-4">
    <div class="flex justify-between flex-wrap gap-2">
        <div>
            <div class="font-semibold text-lg">{{ $conversation->contact?->displayName() }}</div>
            <div class="text-sm text-slate-500">{{ $conversation->contact?->wa_id }} · {{ $conversation->number->label }}</div>
        </div>
        <div class="text-sm text-right">
            <div>Agent: <strong>{{ $conversation->assignedAgent->name ?? '—' }}</strong></div>
            <div>Status: {{ $conversation->status }}</div>
        </div>
    </div>
</div>
<div class="chat-bg rounded-xl p-4 space-y-2" style="background:#efeae2">
@foreach($messages as $m)
    <div class="flex {{ $m->direction==='out'?'justify-end':'justify-start' }}">
        <div class="max-w-[75%] rounded-lg px-3 py-2 shadow-sm {{ $m->direction==='out'?'bg-[#d9fdd3]':'bg-white' }}">
            @if($m->direction==='out' && $m->sender)<div class="text-[11px] font-medium text-emerald-700 mb-0.5">{{ $m->sender->name }}</div>@endif
            @if($m->media_path)
                @if($m->type==='image')<a href="{{ asset('storage/'.$m->media_path) }}" target="_blank"><img src="{{ asset('storage/'.$m->media_path) }}" class="rounded max-w-[200px] mb-1"></a>
                @else<a href="{{ asset('storage/'.$m->media_path) }}" class="text-blue-600 underline text-sm">📎 {{ $m->type }}</a>@endif
            @endif
            @if($m->body)<div class="text-sm whitespace-pre-wrap break-words">{{ $m->body }}</div>@endif
            @foreach($m->flags as $f)
                <div class="mt-1 text-[11px] text-red-700 bg-red-50 rounded px-1.5 py-0.5 inline-block">⚑ {{ $f->rule }} ({{ $f->matched }})</div>
            @endforeach
            <div class="text-[10px] text-slate-400 text-right mt-0.5">{{ $m->sent_at?->format('d M, H:i') }} · {{ $m->status }}</div>
        </div>
    </div>
@endforeach
</div>
<style>.chat-bg{background-image:radial-gradient(#d9d2c7 .5px,transparent .5px);background-size:12px 12px}</style>
<a href="{{ url()->previous() }}" class="inline-block mt-4 text-sm text-slate-500">← Back</a>
@endsection
