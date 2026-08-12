<?php

use App\Http\Middleware\RememberAuthorizeUrl;
use App\Http\Middleware\TrackLastActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Track when each signed-in user was last seen (admin panel reporting).
        $middleware->web(append: [
            TrackLastActive::class,
            RememberAuthorizeUrl::class,
        ]);

        // Telegram posts without a session and without our token. The webhook
        // authenticates itself with the secret Telegram echoes back on every
        // update, which the controller checks before doing anything at all.
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Passport guards the Authorize button with a one-time session token,
        // so a consent page that was reopened behind the person's back —
        // claude.ai reloads it while connecting — refuses their click. A 403
        // is a dead end; a fresh consent screen is one more click. Restart
        // the flow from the URL the middleware above remembered.
        //
        // Caught as AccessDeniedHttpException, because the handler converts
        // every AuthorizationException into one before render callbacks run —
        // Passport's own exception only survives as the `previous` link.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof InvalidAuthTokenException) {
                return null;
            }

            $authorizeUrl = $request->hasSession()
                ? $request->session()->pull('oauth.authorize_url')
                : null;

            if ($authorizeUrl !== null) {
                return redirect()->to(
                    $authorizeUrl.(str_contains($authorizeUrl, '?') ? '&' : '?').'retried=1'
                );
            }
        });
    })->create();
