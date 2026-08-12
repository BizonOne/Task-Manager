<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Keep the last OAuth authorize URL a person opened.
 *
 * Passport guards the Authorize button with a one-time session token, and a
 * connector that reopens the consent page — claude.ai does — quietly
 * invalidates the tab the person is about to click in. When that click is
 * refused, the stashed URL is what lets us restart the consent instead of
 * stranding them on a 403.
 */
class RememberAuthorizeUrl
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && $request->path() === 'oauth/authorize') {
            $request->session()->put('oauth.authorize_url', $request->fullUrl());
        }

        return $next($request);
    }
}
