<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Str;

/**
 * Cleans the HTML produced by the rich-text editors.
 *
 * Descriptions, notes and comments are stored as HTML and rendered unescaped,
 * so whatever reaches the database is what the browser will execute. The
 * editor sanitises nothing useful — a request can carry any markup at all —
 * which made every rich-text field a stored-XSS vector. Everything is filtered
 * here, on write, so the rendering side can stay simple.
 */
class RichText
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * Tags the editors can produce, and nothing else. No <script>, no <iframe>,
     * no inline event handlers, no style attributes.
     */
    private const ALLOWED_HTML = 'p,br,b,strong,i,em,u,s,del,ins,'
        .'ul,ol,li,'
        .'h1,h2,h3,h4,h5,h6,'
        .'blockquote,pre,code,hr,'
        .'a[href|title|target|rel],'
        .'span[class],div[class],'
        .'table,thead,tbody,tr,th,td';

    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim(strip_tags($html, '<img><br><hr>')) === '' && trim($html) === '') {
            return null;
        }

        return static::purifier()->purify($html);
    }

    /**
     * Plain-text version, for previews, search and email subjects.
     */
    public static function toText(?string $html, ?int $limit = null): string
    {
        // Blocks carry their own separation in HTML, so strip the tags and
        // "<p>First</p><p>Second</p>" reads "FirstSecond". Put the break back
        // before the markup goes.
        $spaced = preg_replace('/<(?:br\s*\/?|\/(?:p|div|li|tr|h[1-6]|blockquote|pre))>/i', ' ', (string) $html);

        $text = trim(html_entity_decode(strip_tags($spaced ?? (string) $html), ENT_QUOTES | ENT_HTML5));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $limit === null ? $text : Str::limit($text, $limit);
    }

    /**
     * Whether the value has any visible content once markup is stripped.
     */
    public static function isEmpty(?string $html): bool
    {
        return static::toText($html) === '';
    }

    private static function purifier(): HTMLPurifier
    {
        if (static::$purifier !== null) {
            return static::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.AutoParagraph', false);

        // Links open elsewhere, so make sure they cannot reach back into the
        // opener, and only allow protocols a person would actually paste.
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // Quill marks up its formatting with ql-* classes.
        $config->set('Attr.AllowedClasses', null);

        // The definition cache is a build artifact; keep it out of the repo and
        // tolerate a read-only or ephemeral filesystem.
        $cache = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $config->set('Cache.SerializerPath', is_writable($cache) ? $cache : null);

        return static::$purifier = new HTMLPurifier($config);
    }
}
