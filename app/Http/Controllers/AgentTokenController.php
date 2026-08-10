<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Keys that let an AI agent act as you.
 *
 * A token is shown once, at the moment it is made — after that the server
 * keeps only a hash, the same way it treats passwords. Whoever holds the
 * token holds your access, which is why this page belongs to each person
 * and to nobody else, and why revoking one is a single click.
 */
class AgentTokenController extends Controller
{
    public function index()
    {
        return view('profile.agents', [
            'tokens' => Auth::user()->tokens()->latest()->get(),
            // Shown once, straight after creation, never stored.
            'freshToken' => session('freshToken'),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:60']);

        $token = Auth::user()->createToken($validated['name']);

        return redirect()->route('profile.agents')
            ->with('freshToken', $token->plainTextToken)
            ->with('success', 'Token created. Copy it now — it will not be shown again.');
    }

    public function destroy(Request $request, string $tokenId)
    {
        $token = Auth::user()->tokens()->where('id', $tokenId)->first();

        abort_unless($token !== null, 404);

        $token->delete();

        return back()->with('success', 'Token revoked. Anything holding it is signed out.');
    }
}
