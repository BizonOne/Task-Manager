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
 * A route rather than a static file, served from a path with no `.js` on the
 * end, and asked for with a content hash in the query string. All three,
 * because the first two are not enough on their own: the CDN in front of this
 * application rewrites Cache-Control on anything that looks like a script, no
 * matter what the application said. The hash is what actually guarantees a new
 * deploy is a new URL, and a new URL cannot be served from anybody's cache.
 */
class ServiceWorkerController extends Controller
{
    /**
     * A short fingerprint of the worker, for the query string that makes each
     * version its own URL.
     */
    public static function version(): string
    {
        return substr(md5_file(self::path()) ?: 'dev', 0, 8);
    }

    public static function path(): string
    {
        return resource_path('service-worker/sw.js');
    }

    public function __invoke(): Response
    {
        return response(
            file_get_contents(self::path()),
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
