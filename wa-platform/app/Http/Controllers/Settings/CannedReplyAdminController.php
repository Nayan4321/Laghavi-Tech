<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CannedReply;
use App\Models\WhatsappNumber;
use Illuminate\Http\Request;

class CannedReplyAdminController extends Controller
{
    public function index()
    {
        $replies = CannedReply::with('number:id,label')->orderBy('title')->get();

        return view('settings.canned.index', compact('replies'));
    }

    public function create()
    {
        return view('settings.canned.form', [
            'cannedReply' => new CannedReply,
            'numbers' => WhatsappNumber::orderBy('label')->get(),
        ]);
    }

    public function store(Request $request)
    {
        CannedReply::create($this->validated($request));

        return redirect()->route('settings.canned.index')->with('status', 'Quick reply added.');
    }

    public function edit(CannedReply $cannedReply)
    {
        return view('settings.canned.form', [
            'cannedReply' => $cannedReply,
            'numbers' => WhatsappNumber::orderBy('label')->get(),
        ]);
    }

    public function update(Request $request, CannedReply $cannedReply)
    {
        $cannedReply->update($this->validated($request));

        return redirect()->route('settings.canned.index')->with('status', 'Quick reply updated.');
    }

    public function destroy(CannedReply $cannedReply)
    {
        $cannedReply->delete();

        return back()->with('status', 'Quick reply removed.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'whatsapp_number_id' => ['nullable', 'exists:whatsapp_numbers,id'],
            'shortcut' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4096'],
        ]);
    }
}
