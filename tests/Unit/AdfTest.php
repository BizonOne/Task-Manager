<?php

namespace Tests\Unit;

use App\Support\Jira\Adf;
use PHPUnit\Framework\TestCase;

/**
 * The fallback path: what an issue's text becomes when Jira hasn't rendered it
 * for us. Losing the formatting would be a shame; losing the words would be a
 * bug, so the unknown-node case matters as much as the known ones.
 */
class AdfTest extends TestCase
{
    private function doc(array ...$content): array
    {
        return ['type' => 'doc', 'version' => 1, 'content' => $content];
    }

    private function paragraph(array ...$content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    private function text(string $text, array $marks = []): array
    {
        return array_filter(['type' => 'text', 'text' => $text, 'marks' => $marks]);
    }

    public function test_nothing_in_nothing_out(): void
    {
        $this->assertNull(Adf::toHtml(null));
        $this->assertNull(Adf::toHtml($this->doc()));
    }

    public function test_paragraphs_and_breaks(): void
    {
        $html = Adf::toHtml($this->doc(
            $this->paragraph($this->text('First'), ['type' => 'hardBreak'], $this->text('Second')),
        ));

        $this->assertSame('<p>First<br>Second</p>', $html);
    }

    public function test_marks_wrap_the_text_they_are_on(): void
    {
        $html = Adf::toHtml($this->doc($this->paragraph(
            $this->text('bold', [['type' => 'strong']]),
            $this->text('link', [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]),
        )));

        $this->assertSame('<p><strong>bold</strong><a href="https://example.com">link</a></p>', $html);
    }

    public function test_lists_keep_their_items(): void
    {
        $html = Adf::toHtml($this->doc([
            'type' => 'bulletList',
            'content' => [
                ['type' => 'listItem', 'content' => [$this->paragraph($this->text('One'))]],
                ['type' => 'listItem', 'content' => [$this->paragraph($this->text('Two'))]],
            ],
        ]));

        $this->assertSame('<ul><li><p>One</p></li><li><p>Two</p></li></ul>', $html);
    }

    public function test_a_code_block_is_not_run_as_markup(): void
    {
        $html = Adf::toHtml($this->doc([
            'type' => 'codeBlock',
            'content' => [$this->text('<script>alert(1)</script>')],
        ]));

        $this->assertSame('<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>', $html);
    }

    public function test_a_mention_keeps_the_persons_name(): void
    {
        $html = Adf::toHtml($this->doc($this->paragraph(
            ['type' => 'mention', 'attrs' => ['id' => 'abc', 'text' => '@Ksenija']],
        )));

        // The account itself means nothing here, but the name in the sentence
        // still tells you who was being asked.
        $this->assertSame('<p>@Ksenija</p>', $html);
    }

    public function test_an_attachment_leaves_a_note_where_it_was(): void
    {
        $html = Adf::toHtml($this->doc([
            'type' => 'mediaSingle',
            'content' => [['type' => 'media', 'attrs' => ['alt' => 'passport.png']]],
        ]));

        $this->assertStringContainsString('passport.png', $html);
    }

    public function test_an_unknown_wrapper_loses_its_formatting_not_its_words(): void
    {
        $html = Adf::toHtml($this->doc([
            'type' => 'somethingAtlassianAddedLastTuesday',
            'content' => [$this->paragraph($this->text('Still here'))],
        ]));

        $this->assertSame('<p>Still here</p>', $html);
    }

    public function test_text_is_escaped(): void
    {
        $html = Adf::toHtml($this->doc($this->paragraph($this->text('<img src=x onerror=alert(1)>'))));

        $this->assertStringNotContainsString('<img', $html);
    }
}
