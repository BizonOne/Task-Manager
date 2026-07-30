<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when a signed-in user was last seen, for the admin panel's
 * "last active" column. Throttled so we don't write on every request.
 */
class TrackLastActive
{
    /**
     * Only touch the row once per this many minutes.
     */
    private const THROTTLE_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user
            && ($user->last_active_at === null
                || $user->last_active_at->lt(now()->subMinutes(self::THROTTLE_MINUTES)))
        ) {
            // Write without touching updated_at or firing model events.
            $user->newQuery()->whereKey($user->getKey())->update(['last_active_at' => now()]);
            $user->last_active_at = now();
        }

        return $next($request);
    }
}
