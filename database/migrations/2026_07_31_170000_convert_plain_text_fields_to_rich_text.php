<?php

use App\Support\RichText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Comments, reminder descriptions and routine descriptions used to be plain
 * text: the views escaped them and turned newlines into <br>. They are rich
 * text now, rendered as HTML, so everything already in the table has to be
 * converted — otherwise old entries lose their line breaks, and any '<' a
 * person typed would suddenly be treated as markup.
 */
return new class extends Migration
{
    /** @var array<string, string> table => column */
    private const FIELDS = [
        'task_comments' => 'body',
        'reminders' => 'description',
        'routines' => 'description',
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $table => $column) {
            DB::table($table)
                ->select('id', $column)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($table, $column) {
                    foreach ($rows as $row) {
                        $converted = $this->toHtml($row->{$column});

                        if ($converted !== $row->{$column}) {
                            DB::table($table)->where('id', $row->id)->update([$column => $converted]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Back to plain text. The formatting is gone either way, so keep the
        // words rather than pretending the markup can be restored.
        foreach (self::FIELDS as $table => $column) {
            DB::table($table)
                ->select('id', $column)
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($table, $column) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => RichText::toText($row->{$column}),
                        ]);
                    }
                });
        }
    }

    /**
     * Plain text becomes a paragraph with its line breaks kept. Anything that
     * already carries markup only gets sanitised — the descriptions written in
     * the old admin panel could contain anything at all.
     */
    private function toHtml(string $value): string
    {
        if ($this->looksLikeHtml($value)) {
            return (string) RichText::clean($value);
        }

        return '<p>'.nl2br(e($value), false).'</p>';
    }

    /**
     * Only a tag we recognise counts as markup. "priority < 3 > review" would
     * otherwise be read as HTML, and the sanitiser would drop it as an unknown
     * tag — deleting text somebody wrote.
     */
    private function looksLikeHtml(string $value): bool
    {
        $tags = 'p|br|div|span|ul|ol|li|h[1-6]|strong|b|em|i|u|s|del|ins'
            .'|a|blockquote|pre|code|hr|img|table|thead|tbody|tr|th|td|script|style';

        return preg_match('/<\/?(?:'.$tags.')\b[^>]*>/i', $value) === 1;
    }
};
