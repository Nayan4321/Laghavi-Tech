<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\User;
use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();

        $stats = [
            'numbers' => WhatsappNumber::count(),
            'agents' => User::where('role', 'agent')->count(),
            'open' => Conversation::where('status', 'open')->count(),
            'messages_today' => Message::where('created_at', '>=', $today)->count(),
            'inbound_today' => Message::where('direction', 'in')->where('created_at', '>=', $today)->count(),
            'outbound_today' => Message::where('direction', 'out')->where('created_at', '>=', $today)->count(),
            'unreviewed_flags' => MessageFlag::where('reviewed', false)->count(),
        ];

        $recentFlags = MessageFlag::with(['message.conversation.contact', 'message.sender'])
            ->where('reviewed', false)
            ->latest()
            ->limit(10)
            ->get();

        return view('supervisor.dashboard', compact('stats', 'recentFlags'));
    }

    /** Per-agent performance table — the core "are agents working fairly" view. */
    public function agents()
    {
        $since = now()->subDays(7);

        // Outbound counts + average first-response time per agent.
        $agents = User::where('role', 'agent')->orderBy('name')->get()->map(function (User $agent) use ($since) {
            $sent = Message::where('sender_user_id', $agent->id)
                ->where('direction', 'out')
                ->where('created_at', '>=', $since)
                ->count();

            $assigned = Conversation::where('assigned_user_id', $agent->id)->count();
            $openAssigned = Conversation::where('assigned_user_id', $agent->id)->where('status', 'open')->count();

            $flags = MessageFlag::whereHas('message', function ($q) use ($agent, $since) {
                $q->where('sender_user_id', $agent->id)->where('created_at', '>=', $since);
            })->count();

            $lastActive = Message::where('sender_user_id', $agent->id)->max('created_at');

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'active' => $agent->is_active,
                'sent_7d' => $sent,
                'assigned' => $assigned,
                'open_assigned' => $openAssigned,
                'flags_7d' => $flags,
                'avg_response_secs' => $this->avgResponseSeconds($agent->id, $since),
                'last_active' => $lastActive,
            ];
        });

        return view('supervisor.agents', ['agents' => $agents]);
    }

    /**
     * Average seconds between an inbound customer message and the agent's next
     * outbound reply in the same conversation (a simple first-response proxy).
     */
    protected function avgResponseSeconds(int $agentId, $since): ?int
    {
        $replies = Message::where('sender_user_id', $agentId)
            ->where('direction', 'out')
            ->where('created_at', '>=', $since)
            ->get(['id', 'conversation_id', 'sent_at']);

        $diffs = [];
        foreach ($replies as $reply) {
            $prevInbound = Message::where('conversation_id', $reply->conversation_id)
                ->where('direction', 'in')
                ->where('sent_at', '<', $reply->sent_at)
                ->max('sent_at');

            if ($prevInbound) {
                $diffs[] = $reply->sent_at->diffInSeconds(\Illuminate\Support\Carbon::parse($prevInbound));
            }
        }

        if (empty($diffs)) {
            return null;
        }

        return (int) round(array_sum($diffs) / count($diffs));
    }
}
