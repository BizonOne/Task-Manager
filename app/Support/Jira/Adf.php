<?php

namespace App\Support\Jira;

/**
 * Atlassian Document Format to HTML.
 *
 * Jira stores rich text as a JSON tree, and its API will render that tree to
 * HTML for us — which is what the import uses when it can. This is the way
 * back when it can't: an issue fetched without `renderedFields`, or a comment
 * whose rendered body came back empty.
 *
 * Only the nodes people actually type are handled. Anything unknown falls
 * through to its children, so an unrecognised wrapper loses its formatting
 * rather than losing the words inside it.
 */
class Adf
{
    /**
     * @param  array<string, mixed>|null  $document
     */
    public static function toHtml(?array $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $html = trim(self::children($document));

        return $html === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function node(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'text' => self::text($node),
            'hardBreak' => '<br>',
            'paragraph' => self::wrap('p', self::children($node)),
            'heading' => self::heading($node),
            'blockquote' => self::wrap('blockquote', self::children($node)),
            'bulletList' => self::wrap('ul', self::children($node)),
            'orderedList' => self::wrap('ol', self::children($node)),
            'listItem' => self::wrap('li', self::children($node)),
            'codeBlock' => '<pre><code>'.e(self::plain($node)).'</code></pre>',
            'rule' => '<hr>',
            'table' => self::wrap('table', self::children($node)),
            'tableRow' => self::wrap('tr', self::children($node)),
            'tableHeader' => self::wrap('th', self::children($node)),
            'tableCell' => self::wrap('td', self::children($node)),
            // A mention is a person, and the person may well not exist here.
            // Their name is the part worth keeping.
            'mention' => e((string) ($node['attrs']['text'] ?? '@unknown')),
            'emoji' => e((string) ($node['attrs']['text'] ?? $node['attrs']['shortName'] ?? '')),
            'date' => e((string) ($node['attrs']['timestamp'] ?? '')),
            'inlineCard', 'blockCard', 'embedCard' => self::card($node),
            // Attachments are not carried over, so say so rather than leaving a
            // hole where a screenshot used to be.
            'media', 'mediaSingle', 'mediaGroup' => self::media($node),
            default => self::children($node),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function children(array $node): string
    {
        $out = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= self::node($child);
            }
        }

        return $out;
    }

    /**
     * A text node, wrapped in whatever marks it carries.
     *
     * @param  array<string, mixed>  $node
     */
    private static function text(array $node): string
    {
        $html = e((string) ($node['text'] ?? ''));

        foreach ($node['marks'] ?? [] as $mark) {
            $html = match ($mark['type'] ?? '') {
                'strong' => '<strong>'.$html.'</strong>',
                'em' => '<em>'.$html.'</em>',
                'underline' => '<u>'.$html.'</u>',
                'strike' => '<s>'.$html.'</s>',
                'code' => '<code>'.$html.'</code>',
                'link' => '<a href="'.e((string) ($mark['attrs']['href'] ?? '#')).'">'.$html.'</a>',
                default => $html,
            };
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function heading(array $node): string
    {
        $level = min(6, max(1, (int) ($node['attrs']['level'] ?? 2)));

        return self::wrap('h'.$level, self::children($node));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function card(array $node): string
    {
        $url = (string) ($node['attrs']['url'] ?? '');

        return $url === '' ? '' : '<a href="'.e($url).'">'.e($url).'</a>';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function media(array $node): string
    {
        // A mediaSingle wraps a media node; go looking for the name either way.
        $name = $node['attrs']['alt'] ?? null;

        foreach ($node['content'] ?? [] as $child) {
            $name ??= $child['attrs']['alt'] ?? null;
        }

        return '<p><em>[attachment'.($name ? ': '.e((string) $name) : '').']</em></p>';
    }

    /**
     * The words inside a node, with no markup at all — for code blocks.
     *
     * @param  array<string, mixed>  $node
     */
    private static function plain(array $node): string
    {
        $out = (string) ($node['text'] ?? '');

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= self::plain($child);
            }
        }

        return $out;
    }

    private static function wrap(string $tag, string $inner): string
    {
        return $inner === '' ? '' : "<{$tag}>{$inner}</{$tag}>";
    }
}
