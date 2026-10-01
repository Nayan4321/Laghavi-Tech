<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\MessageFlag;
use Illuminate\Http\Request;

class FlagReviewController extends Controller
{
    public function index(Request $request)
    {
        $flags = MessageFlag::with(['message.conversation.contact', 'message.conversation.number', 'message.sender'])
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->query('state') === 'open', fn ($q) => $q->where('reviewed', false))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('supervisor.flags', compact('flags'));
    }

    public function review(MessageFlag $flag)
    {
        $flag->update(['reviewed' => true]);

        return back()->with('status', 'Flag marked reviewed.');
    }
}
