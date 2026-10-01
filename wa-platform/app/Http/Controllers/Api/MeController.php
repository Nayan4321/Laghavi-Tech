<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** Lightweight polling endpoint: identity + unread totals for badges. */
    public function show(Request $request)
    {
        $user = $request->user();
        $ids = $user->visibleNumberIds();

        $unread = Conversation::whereIn('whatsapp_number_id', $ids)->sum('unread_count');
        $open = Conversation::whereIn('whatsapp_number_id', $ids)->where('status', 'open')->count();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'is_supervisor' => $user->isSupervisor(),
            'unread_total' => (int) $unread,
            'open_total' => $open,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
