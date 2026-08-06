<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the service worker, and does it without a cache.
 *
 * This started as a plain file in public/, which the web server handed out
 * with `Cache-Control: public, max-age=14400`. Updating a service worker is
 * subject to the ordinary HTTP cache, so that told every browser to keep
 * running the version it already had for four hours — and two fixes to the
 * push handler reached nobody. A bug you cannot deploy a fix to is the worst
 * kind, and caching this file is how you get one.
 *
 * A route rather than a header on a static file because it is ours either way,
 * and this one cannot be undone by a CDN in front of it.
 */
class ServiceWorkerController extends Controller
{
    public function __invoke(): Response
    {
        return response(
            file_get_contents(resource_path('service-worker/sw.js')),
            200,
            [
                'Content-Type' => 'text/javascript; charset=utf-8',
                // Revalidate every time. The file is a few hundred bytes and
                // being one version behind is the whole problem.
                'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
                // It has to be allowed to act for the whole site, not just its
                // own directory.
                'Service-Worker-Allowed' => '/',
            ],
        );
    }
}
