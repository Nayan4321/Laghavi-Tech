<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NumberAdminController extends Controller
{
    public function index()
    {
        $numbers = WhatsappNumber::withCount(['conversations', 'agents'])->get();

        return view('settings.numbers.index', compact('numbers'));
    }

    public function create()
    {
        $number = new WhatsappNumber(['webhook_verify_token' => Str::random(24)]);
        $agents = User::where('role', 'agent')->orderBy('name')->get();

        return view('settings.numbers.form', ['number' => $number, 'agents' => $agents, 'assigned' => []]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $number = WhatsappNumber::create($data);
        $number->agents()->sync($request->input('agents', []));

        return redirect()->route('settings.numbers.index')->with('status', 'Number added.');
    }

    public function edit(WhatsappNumber $number)
    {
        $agents = User::where('role', 'agent')->orderBy('name')->get();
        $assigned = $number->agents()->pluck('users.id')->all();

        return view('settings.numbers.form', compact('number', 'agents', 'assigned'));
    }

    public function update(Request $request, WhatsappNumber $number)
    {
        $data = $this->validated($request, $number);
        // Keep existing token if the field was left blank.
        if (empty($data['access_token'])) {
            unset($data['access_token']);
        }
        $number->update($data);
        $number->agents()->sync($request->input('agents', []));

        return redirect()->route('settings.numbers.index')->with('status', 'Number updated.');
    }

    public function destroy(WhatsappNumber $number)
    {
        $number->delete();

        return back()->with('status', 'Number removed.');
    }

    protected function validated(Request $request, ?WhatsappNumber $number = null): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'display_phone' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'access_token' => [$number ? 'nullable' : 'required', 'string'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
