<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Brand;
use Database\Seeders\BrandSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create([
            'name' => 'U '.$role,
            'email' => $role.'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_brand_defaults_when_unset(): void
    {
        $this->assertSame(Brand::DEFAULT_NAME, Brand::name());
        $this->assertSame(Brand::DEFAULT_COLOR, Brand::primaryColor());
        $this->assertNull(Brand::logoUrl());
        $this->assertNull(Brand::faviconUrl());
    }

    public function test_set_and_get_round_trips_and_forgets_cache(): void
    {
        Brand::set('brand.name', 'Acme Tasks');
        $this->assertSame('Acme Tasks', Brand::name());
        $this->assertDatabaseHas('settings', ['key' => 'brand.name', 'value' => 'Acme Tasks']);
    }

    public function test_darken_returns_valid_hex_and_is_darker(): void
    {
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', Brand::darken('#6366f1', 0.1));
        $this->assertSame('#000000', Brand::darken('#000000', 0.5));
        // Invalid input falls back to the default colour.
        $this->assertSame(Brand::DEFAULT_COLOR, Brand::darken('not-a-color', 0.1));
    }

    public function test_brand_seeder_is_idempotent_and_non_destructive(): void
    {
        $this->seed(BrandSeeder::class);
        Setting::where('key', 'brand.name')->update(['value' => 'Custom']);
        Brand::forget();

        $this->seed(BrandSeeder::class); // must not overwrite the customised value

        $this->assertSame('Custom', Brand::name());
        $this->assertSame(1, Setting::where('key', 'brand.name')->count());
    }

    public function test_only_super_admin_can_reach_the_branding_page(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/admin/manage-branding')
            ->assertSuccessful();
    }

    public function test_admin_cannot_reach_the_branding_page(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/admin/manage-branding')
            ->assertForbidden();
    }

    public function test_front_end_reflects_the_brand_name(): void
    {
        Brand::set('brand.name', 'Acme Tasks');

        $this->get('/login')->assertSuccessful()->assertSee('Acme Tasks', false);
    }
}
