<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Central accessor for the app's branding (name, logo, favicon, colour).
 *
 * Values live in the settings table and are memoised per request. Reads are
 * defensive: before the settings migration has run (e.g. during migrate on a
 * fresh deploy) they fall back to defaults instead of throwing.
 */
class Brand
{
    public const DEFAULT_NAME = 'Task Manager';

    public const DEFAULT_COLOR = '#6366f1';

    /** @var array<string, string|null>|null */
    protected static ?array $cache = null;

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (static::$cache === null) {
            try {
                static::$cache = Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                static::$cache = [];
            }
        }

        return static::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::all()[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        static::$cache = null;
    }

    public static function forget(): void
    {
        static::$cache = null;
    }

    public static function name(): string
    {
        $name = static::get('brand.name');

        return $name !== null && $name !== '' ? $name : self::DEFAULT_NAME;
    }

    public static function primaryColor(): string
    {
        $color = static::get('brand.primary_color');

        return $color !== null && $color !== '' ? $color : self::DEFAULT_COLOR;
    }

    /**
     * The logo as a base64 data URI, or null to fall back to the bundled asset.
     * A data: URI works anywhere a normal image URL does (src / href).
     */
    public static function logoUrl(): ?string
    {
        $data = static::get('brand.logo_data');

        return $data !== null && $data !== '' ? $data : null;
    }

    public static function faviconUrl(): ?string
    {
        $data = static::get('brand.favicon_data');

        return $data !== null && $data !== '' ? $data : null;
    }

    /**
     * Darken a hex colour by a fraction (0–1). Used to derive the 600/700
     * shades of the primary colour for the front-end CSS variables.
     */
    public static function darken(string $hex, float $amount): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return self::DEFAULT_COLOR;
        }

        $factor = max(0.0, min(1.0, 1 - $amount));
        $r = (int) (hexdec(substr($hex, 0, 2)) * $factor);
        $g = (int) (hexdec(substr($hex, 2, 2)) * $factor);
        $b = (int) (hexdec(substr($hex, 4, 2)) * $factor);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
