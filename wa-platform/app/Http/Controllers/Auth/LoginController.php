<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect('/inbox');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $request->user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'This account is disabled.',
            ]);
        }

        $request->session()->regenerate();
        AuditLogger::log('login');

        return redirect()->intended($request->user()->isSupervisor() ? '/supervisor' : '/inbox');
    }

    public function logout(Request $request)
    {
        AuditLogger::log('logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
