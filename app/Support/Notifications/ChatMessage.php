<?php

namespace App\Support\Notifications;

use Illuminate\Support\Str;

/**
 * A notification as a chat app wants it.
 *
 * An email can afford a header, a table and a footer. A message in Telegram
 * gets about three lines before somebody scrolls past it, so this is the same
 * news said in the shortest form that still answers "what happened, to what,
 * and where do I go".
 */
class ChatMessage
{
    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines = [],
        public readonly ?string $url = null,
    ) {}

    /**
     * Build one from a notification's stored payload.
     *
     * Every notification here already writes a `message` and a `url` for the
     * bell, which is exactly what a chat message needs — so a notification
     * gets a chat version whether or not anybody wrote one for it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            title: (string) ($payload['message'] ?? $payload['task_title'] ?? 'Update'),
            lines: array_values(array_filter([
                isset($payload['project_name']) ? 'Project: '.$payload['project_name'] : null,
                isset($payload['excerpt']) ? '“'.Str::limit((string) $payload['excerpt'], 200).'”' : null,
            ])),
            url: isset($payload['url']) ? (string) $payload['url'] : null,
        );
    }

    /**
     * Telegram's HTML: a very small subset, and anything else must be escaped
     * or the message is rejected outright.
     */
    public function toTelegramHtml(): string
    {
        $out = '<b>'.e($this->title).'</b>';

        foreach ($this->lines as $line) {
            $out .= "\n".e($line);
        }

        if ($this->url !== null) {
            $out .= "\n\n".'<a href="'.e($this->url).'">Open</a>';
        }

        return $out;
    }

    /**
     * Slack's mrkdwn, which is not Markdown and has its own three characters
     * that have to be escaped.
     */
    public function toSlackMarkdown(): string
    {
        $escape = fn (string $text) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        $out = '*'.$escape($this->title).'*';

        foreach ($this->lines as $line) {
            $out .= "\n".$escape($line);
        }

        if ($this->url !== null) {
            $out .= "\n<".$this->url.'|Open>';
        }

        return $out;
    }

    public function toText(): string
    {
        return trim($this->title."\n".implode("\n", $this->lines)."\n".(string) $this->url);
    }
}
