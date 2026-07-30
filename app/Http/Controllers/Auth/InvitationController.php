<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Invitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    /**
     * Show the "set your password" form behind an invitation link.
     */
    public function show(string $token)
    {
        $user = Invitations::findByToken($token);
        abort_if($user === null, 404);

        return view('auth.invitation', compact('user', 'token'));
    }

    /**
     * Accept the invitation: the invitee sets their own password, then we sign
     * them in so they land straight in the app.
     */
    public function accept(Request $request, string $token)
    {
        $user = Invitations::findByToken($token);
        abort_if($user === null, 404);

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        Invitations::accept($user, $request->input('password'));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome aboard! Your account is ready.');
    }
}
