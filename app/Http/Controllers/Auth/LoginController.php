<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        // The second argument is the "Remember me" box. Without it the box
        // was decoration: the form sent it and nothing ever read it.
        if (Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            $request->session()->regenerate();

            // The dashboard is served at '/', so the literal path 'dashboard'
            // 404s — resolve the route by name instead.
            return redirect()->intended(route('dashboard'));
        }

        // An invited user has no password yet — point them at their invite
        // instead of the generic "wrong credentials" dead end.
        $invited = User::where('email', $request->input('email'))->first();
        if ($invited && $invited->isPendingInvitation()) {
            return back()->withErrors([
                'email' => 'Your invitation is still pending. Please use the invitation link we emailed you to set a password.',
            ]);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
