<?php

namespace Tests\Feature;

use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emails cannot use the base64 data URI the app stores its logo as — mail
 * clients block `data:` in <img src> — so the logo is also served over HTTP,
 * in a format those clients can actually render.
 */
class BrandLogoRouteTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#6366f1"/></svg>';

    private function setLogo(string $mime, string $base64): void
    {
        Brand::set('brand.logo_data', "data:{$mime};base64,{$base64}");
        Brand::forget();
    }

    public function test_a_raster_logo_is_served_untouched(): void
    {
        $this->setLogo('image/png', self::PNG);

        $response = $this->get(route('brand.logo'));

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG, true), $response->getContent());
    }

    public function test_the_route_is_public_so_mail_clients_can_load_it(): void
    {
        $this->setLogo('image/png', self::PNG);

        // No authenticated user — must not redirect to login.
        $this->get(route('brand.logo'))->assertSuccessful();
    }

    public function test_it_404s_when_no_logo_is_configured(): void
    {
        Brand::set('brand.logo_data', null);
        Brand::forget();

        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_the_email_url_is_fingerprinted_so_a_new_logo_busts_caches(): void
    {
        $this->setLogo('image/png', self::PNG);
        $first = Brand::emailLogoUrl();

        $this->assertStringContainsString('v=', (string) $first);

        // A different logo must produce a different URL, or Gmail's image proxy
        // would keep serving the old one.
        $this->setLogo('image/gif', 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $this->assertNotSame($first, Brand::emailLogoUrl());
    }

    public function test_email_logo_url_is_null_without_a_logo_so_emails_fall_back_to_the_wordmark(): void
    {
        Brand::set('brand.logo_data', null);
        Brand::forget();

        $this->assertNull(Brand::emailLogoUrl());
        $this->assertNull(Brand::emailLogoBinary());
    }

    public function test_a_malformed_logo_value_does_not_break_emails(): void
    {
        Brand::set('brand.logo_data', 'not-a-data-uri');
        Brand::forget();

        $this->assertNull(Brand::logoBinary());
        $this->assertNull(Brand::emailLogoUrl());
        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_an_unrenderable_format_falls_back_to_the_wordmark_rather_than_a_broken_image(): void
    {
        // A format no mail client renders and that we cannot rasterise.
        $this->setLogo('image/x-icon', self::PNG);

        $this->assertNull(Brand::emailLogoUrl(), 'Emails must not link a logo they cannot display.');
        $this->assertNull(Brand::emailLogoBinary());
        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_an_svg_logo_is_rasterised_to_png_for_email(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is not installed in this environment (it is in production).');
        }

        $this->setLogo('image/svg+xml', base64_encode(self::SVG));

        // Gmail and Outlook show SVG as a broken image, so the route must
        // hand back a PNG instead.
        $binary = Brand::emailLogoBinary();
        $this->assertNotNull($binary);
        $this->assertSame('image/png', $binary['mime']);
        $this->assertStringStartsWith("\x89PNG", $binary['bytes']);

        $this->get(route('brand.logo'))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_an_svg_logo_is_still_offered_to_emails_when_it_can_be_rasterised(): void
    {
        $this->setLogo('image/svg+xml', base64_encode(self::SVG));

        // With Imagick the URL is offered; without it, emails quietly fall back
        // to the wordmark instead of linking an image Gmail cannot show.
        $expected = class_exists(\Imagick::class);
        $this->assertSame($expected, Brand::emailLogoUrl() !== null);
    }
}
