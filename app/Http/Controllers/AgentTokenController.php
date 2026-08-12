<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;

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
            // OAuth grants: apps that asked and were authorized, one row per
            // app whatever number of tokens it has collected since.
            'connections' => Token::query()
                ->where('user_id', Auth::id())
                ->where('revoked', false)
                ->where('expires_at', '>', now())
                ->with('client')
                ->latest('created_at')
                ->get()
                ->groupBy('client_id')
                ->map(fn ($tokens) => (object) [
                    'client_id' => $tokens->first()->client_id,
                    'name' => $tokens->first()->client->name ?? 'Unnamed app',
                    'since' => $tokens->min('created_at'),
                    'last_issued' => $tokens->max('created_at'),
                ])
                ->values(),
        ]);
    }

    /**
     * Cut an OAuth-connected app off: every live token it holds for this
     * person is revoked, refresh tokens with them.
     */
    public function destroyConnection(Request $request, string $clientId)
    {
        $tokens = Token::query()
            ->where('user_id', Auth::id())
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->get();

        abort_if($tokens->isEmpty(), 404);

        foreach ($tokens as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        return back()->with('success', 'Connection revoked. The app is signed out.');
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
