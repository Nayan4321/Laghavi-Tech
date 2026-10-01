<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserAdminController extends Controller
{
    public function index()
    {
        $users = User::withCount('numbers')->orderBy('role')->orderBy('name')->get();

        return view('settings.users.index', compact('users'));
    }

    public function create()
    {
        return view('settings.users.form', [
            'user' => new User(['role' => 'agent', 'is_active' => true]),
            'numbers' => WhatsappNumber::orderBy('label')->get(),
            'assigned' => [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(['owner', 'supervisor', 'agent'])],
            'password' => ['required', 'confirmed', Password::min(8)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::create($data);
        $user->numbers()->sync($request->input('numbers', []));

        return redirect()->route('settings.users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('settings.users.form', [
            'user' => $user,
            'numbers' => WhatsappNumber::orderBy('label')->get(),
            'assigned' => $user->numbers()->pluck('whatsapp_numbers.id')->all(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['owner', 'supervisor', 'agent'])],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->numbers()->sync($request->input('numbers', []));

        return redirect()->route('settings.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }
        $user->delete();

        return back()->with('status', 'User removed.');
    }
}
