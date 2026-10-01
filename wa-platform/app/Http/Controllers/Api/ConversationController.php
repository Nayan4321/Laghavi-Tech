<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AuditLogger;
use App\Services\OutboundMessageService;
use Illuminate\Http\Request;

/**
 * JSON API consumed by the agent inbox PWA.
 * Agents see only conversations on numbers assigned to them; supervisors/owners
 * see everything.
 */
class ConversationController extends Controller
{
    /** List conversations the current user may see. */
    public function index(Request $request)
    {
        $user = $request->user();
        $numberIds = $user->visibleNumberIds();

        $query = Conversation::query()
            ->with(['contact', 'number:id,label,display_phone', 'assignedAgent:id,name'])
            ->whereIn('whatsapp_number_id', $numberIds)
            ->orderByDesc('last_message_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($numberId = $request->query('number_id')) {
            $query->where('whatsapp_number_id', $numberId);
        }
        if ($search = $request->query('q')) {
            $query->whereHas('contact', function ($c) use ($search) {
                $c->where('wa_id', 'like', "%{$search}%")
                    ->orWhere('profile_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return $query->paginate(30)->through(fn (Conversation $c) => $this->summary($c));
    }

    /** Full message history for one conversation. */
    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);

        AuditLogger::log('viewed_conversation', $conversation);

        // Opening a conversation clears the unread counter for agents.
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'type' => $m->type,
                'body' => $m->body,
                'media_url' => $m->media_path ? asset('storage/'.$m->media_path) : null,
                'status' => $m->status,
                'sender' => $m->sender?->name,
                'sent_at' => $m->sent_at?->toIso8601String(),
            ]);

        return [
            'conversation' => $this->summary($conversation->load(['contact', 'number', 'assignedAgent'])),
            'window_open' => $conversation->windowOpen(),
            'messages' => $messages,
        ];
    }

    /** Send a free-form text reply. */
    public function sendMessage(Request $request, Conversation $conversation, OutboundMessageService $outbound)
    {
        $this->authorizeAccess($request, $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $message = $outbound->sendText($conversation, $request->user(), $data['body']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send: '.$e->getMessage()], 502);
        }

        return response()->json(['id' => $message->id, 'status' => $message->status], 201);
    }

    public function assign(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);
        $conversation->update(['assigned_user_id' => $request->user()->id]);
        AuditLogger::log('claimed_conversation', $conversation);

        return response()->json(['ok' => true]);
    }

    public function resolve(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);
        $conversation->update(['status' => $request->boolean('reopen') ? 'open' : 'resolved']);

        return response()->json(['ok' => true, 'status' => $conversation->status]);
    }

    protected function summary(Conversation $c): array
    {
        return [
            'id' => $c->id,
            'contact_name' => $c->contact?->displayName(),
            'wa_id' => $c->contact?->wa_id,
            'number' => $c->number?->label,
            'number_id' => $c->whatsapp_number_id,
            'status' => $c->status,
            'unread' => $c->unread_count,
            'assigned_to' => $c->assignedAgent?->name,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /** Agents may only touch conversations on their assigned numbers. */
    protected function authorizeAccess(Request $request, Conversation $conversation): void
    {
        $user = $request->user();
        if (! in_array($conversation->whatsapp_number_id, $user->visibleNumberIds(), true)) {
            abort(403, 'This conversation is not on a number assigned to you.');
        }
    }
}
