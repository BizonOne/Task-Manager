<?php

namespace App\Http\Controllers;

use App\Support\Brand;
use Illuminate\Http\Response;

class BrandAssetController extends Controller
{
    /**
     * Serve the brand logo over HTTP in an email-safe format.
     *
     * Branding is stored as a base64 data URI, which works inline in the app's
     * own HTML but is blocked by email clients. Emails point their <img> here
     * instead. An SVG logo is rasterised to PNG first, because Gmail and
     * Outlook render SVG as a broken image. Public on purpose — the logo is
     * already shown on the login page.
     */
    public function logo(): Response
    {
        $logo = Brand::emailLogoBinary();

        abort_if($logo === null, 404);

        return response($logo['bytes'], 200, [
            'Content-Type' => $logo['mime'],
            // The URL carries a ?v= fingerprint, so this can be cached hard.
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }
}
