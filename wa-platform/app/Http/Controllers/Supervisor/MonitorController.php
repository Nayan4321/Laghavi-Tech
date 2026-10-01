<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitorController extends Controller
{
    /** Browse every conversation across all numbers. */
    public function index(Request $request)
    {
        $query = Conversation::query()
            ->with(['contact', 'number:id,label', 'assignedAgent:id,name'])
            ->orderByDesc('last_message_at');

        if ($request->filled('number_id')) {
            $query->where('whatsapp_number_id', $request->integer('number_id'));
        }
        if ($request->filled('agent_id')) {
            $query->where('assigned_user_id', $request->integer('agent_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->whereHas('contact', fn ($c) => $c
                ->where('wa_id', 'like', "%{$q}%")
                ->orWhere('profile_name', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%"));
        }

        $conversations = $query->paginate(25)->withQueryString();
        $numbers = \App\Models\WhatsappNumber::get(['id', 'label']);
        $agents = \App\Models\User::where('role', 'agent')->get(['id', 'name']);

        return view('supervisor.conversations', compact('conversations', 'numbers', 'agents'));
    }

    /** Read-only transcript of any conversation (full monitoring view). */
    public function show(Conversation $conversation)
    {
        AuditLogger::log('supervisor_viewed_conversation', $conversation);

        $conversation->load(['contact', 'number', 'assignedAgent']);
        $messages = $conversation->messages()->with(['sender:id,name', 'flags'])->orderBy('id')->get();

        return view('supervisor.conversation', compact('conversation', 'messages'));
    }

    /** Export messages as CSV for offline review / records. */
    public function export(Request $request): StreamedResponse
    {
        AuditLogger::log('exported_messages', null, $request->query());

        $filename = 'messages-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'number', 'contact', 'direction', 'agent', 'type', 'status', 'body']);

            Message::query()
                ->with(['conversation.contact', 'conversation.number', 'sender'])
                ->when($request->filled('number_id'), fn ($q) => $q->where('whatsapp_number_id', $request->integer('number_id')))
                ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $m) {
                        fputcsv($out, [
                            $m->sent_at?->toDateTimeString(),
                            $m->conversation->number->label ?? '',
                            $m->conversation->contact?->displayName() ?? '',
                            $m->direction,
                            $m->sender->name ?? '',
                            $m->type,
                            $m->status,
                            $m->body,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
