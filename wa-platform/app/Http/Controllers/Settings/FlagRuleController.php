<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\FlagRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class FlagRuleController extends Controller
{
    public function index()
    {
        $rules = FlagRule::orderBy('name')->get();

        return view('settings.flag_rules.index', compact('rules'));
    }

    public function create()
    {
        return view('settings.flag_rules.form', ['flagRule' => new FlagRule(['severity' => 'medium', 'applies_to' => 'out', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        FlagRule::create($this->validated($request));
        Cache::forget('flag_rules_active');

        return redirect()->route('settings.flag-rules.index')->with('status', 'Rule added.');
    }

    public function edit(FlagRule $flagRule)
    {
        return view('settings.flag_rules.form', compact('flagRule'));
    }

    public function update(Request $request, FlagRule $flagRule)
    {
        $flagRule->update($this->validated($request));
        Cache::forget('flag_rules_active');

        return redirect()->route('settings.flag-rules.index')->with('status', 'Rule updated.');
    }

    public function destroy(FlagRule $flagRule)
    {
        $flagRule->delete();
        Cache::forget('flag_rules_active');

        return back()->with('status', 'Rule removed.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'keywords' => ['required', 'string'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'applies_to' => ['required', Rule::in(['in', 'out', 'both'])],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
