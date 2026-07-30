<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Brand;
use Illuminate\Database\Seeder;

/**
 * Seeds default branding. Idempotent and factory-free: only fills in a
 * setting when it is missing, so it never overwrites customised branding
 * on re-runs (e.g. every deploy).
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'brand.name' => Brand::DEFAULT_NAME,
            'brand.primary_color' => Brand::DEFAULT_COLOR,
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        Brand::forget();

        $this->command?->info('Branding defaults ready.');
    }
}
