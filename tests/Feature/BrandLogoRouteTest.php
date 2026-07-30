<?php

namespace Tests\Feature;

use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emails cannot use the base64 data URI the app stores its logo as — mail
 * clients block `data:` in <img src> — so the logo is also served over HTTP.
 */
class BrandLogoRouteTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_the_logo_is_served_with_its_own_mime_type(): void
    {
        Brand::set('brand.logo_data', 'data:image/png;base64,'.self::PNG);

        $response = $this->get(route('brand.logo'));

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG, true), $response->getContent());
    }

    public function test_the_route_is_public_so_mail_clients_can_load_it(): void
    {
        Brand::set('brand.logo_data', 'data:image/png;base64,'.self::PNG);

        // No authenticated user — must not redirect to login.
        $this->get(route('brand.logo'))->assertSuccessful();
    }

    public function test_it_404s_when_no_logo_is_configured(): void
    {
        Brand::set('brand.logo_data', null);
        Brand::forget();

        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_email_logo_url_is_null_without_a_logo_so_emails_fall_back_to_the_wordmark(): void
    {
        Brand::set('brand.logo_data', null);
        Brand::forget();
        $this->assertNull(Brand::emailLogoUrl());

        Brand::set('brand.logo_data', 'data:image/png;base64,'.self::PNG);
        Brand::forget();
        $this->assertSame(route('brand.logo'), Brand::emailLogoUrl());
    }

    public function test_a_malformed_logo_value_does_not_break_emails(): void
    {
        Brand::set('brand.logo_data', 'not-a-data-uri');
        Brand::forget();

        $this->assertNull(Brand::logoBinary());
        $this->assertNull(Brand::emailLogoUrl());
        $this->get(route('brand.logo'))->assertNotFound();
    }
}
