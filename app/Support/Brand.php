<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Imagick;
use ImagickPixel;

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
     * Decode the stored logo data URI into raw bytes plus its MIME type.
     *
     * Email clients block `data:` URIs in <img src>, so mail has to link the
     * logo over HTTP instead — see the `brand.logo` route.
     *
     * @return array{mime: string, bytes: string}|null
     */
    public static function logoBinary(): ?array
    {
        $uri = static::logoUrl();
        if ($uri === null) {
            return null;
        }

        if (! preg_match('#^data:(?<mime>[-\w.+/]+);base64,(?<data>.+)$#s', $uri, $m)) {
            return null;
        }

        $bytes = base64_decode($m['data'], true);

        return $bytes === false || $bytes === ''
            ? null
            : ['mime' => $m['mime'], 'bytes' => $bytes];
    }

    /**
     * MIME types that email clients reliably render. Notably absent: SVG —
     * Gmail and Outlook show a broken image for it, which looks worse than no
     * logo at all, so SVG logos are rasterised below instead.
     */
    private const EMAIL_SAFE_MIMES = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'];

    /**
     * Short hash of the stored logo, used to bust caches (Gmail proxies and
     * caches remote images) when the branding changes.
     */
    public static function logoFingerprint(): ?string
    {
        $uri = static::logoUrl();

        return $uri === null ? null : substr(sha1($uri), 0, 10);
    }

    /**
     * The logo in a format email clients can actually display.
     *
     * Raster logos are passed through untouched; an SVG is rasterised to PNG
     * (cached, since that costs real work). Returns null when there is no logo
     * or it cannot be made email-safe — callers then fall back to the wordmark.
     *
     * @return array{mime: string, bytes: string}|null
     */
    public static function emailLogoBinary(): ?array
    {
        $logo = static::logoBinary();
        if ($logo === null) {
            return null;
        }

        if (in_array(strtolower($logo['mime']), self::EMAIL_SAFE_MIMES, true)) {
            return $logo;
        }

        if (! static::isSvgMime($logo['mime'])) {
            return null;
        }

        $png = Cache::rememberForever(
            'brand.email_logo_png.'.static::logoFingerprint(),
            fn (): ?string => static::rasterise($logo['bytes']),
        );

        return $png === null ? null : ['mime' => 'image/png', 'bytes' => $png];
    }

    /**
     * Absolute URL of the logo for use in emails, or null when the logo cannot
     * be shown there — in which case the email header uses the text wordmark.
     */
    public static function emailLogoUrl(): ?string
    {
        $logo = static::logoBinary();

        if ($logo === null) {
            return null;
        }

        // Don't rasterise just to build a URL — only check that we could.
        $displayable = in_array(strtolower($logo['mime']), self::EMAIL_SAFE_MIMES, true)
            || (static::isSvgMime($logo['mime']) && class_exists(Imagick::class));

        return $displayable
            ? route('brand.logo', ['v' => static::logoFingerprint()])
            : null;
    }

    private static function isSvgMime(string $mime): bool
    {
        return str_contains(strtolower($mime), 'svg');
    }

    /**
     * Render SVG bytes to a PNG wide enough to stay sharp on retina screens.
     * Returns null when Imagick is unavailable or the SVG cannot be read.
     */
    private static function rasterise(string $svg): ?string
    {
        if (! class_exists(Imagick::class)) {
            return null;
        }

        try {
            $image = new Imagick;
            $image->setBackgroundColor(new ImagickPixel('transparent'));
            $image->readImageBlob($svg);
            $image->setImageFormat('png32');
            // The email header shows the logo at 44px; render 2x for retina.
            $image->resizeImage(88, 0, Imagick::FILTER_LANCZOS, 1);
            $png = $image->getImageBlob();
            $image->clear();

            return $png !== '' ? $png : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
