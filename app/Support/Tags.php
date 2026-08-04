<?php

namespace App\Support;

use App\Models\Tag;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Putting words on a task, and taking them off again.
 */
class Tags
{
    /**
     * At most this many on one task. Not a technical limit — a task wearing
     * thirty tags has been filed by somebody who meant to write a description.
     */
    public const MAX = 12;

    /**
     * Set a task's tags from what somebody typed.
     *
     * A null input means the form never carried the box at all, which is not
     * the same as an empty one; only the second clears them.
     */
    public static function apply(Task $task, ?string $input): void
    {
        if ($input === null) {
            return;
        }

        $task->tags()->sync(self::parse($input)->pluck('id'));
        $task->unsetRelation('tags');
    }

    /**
     * The tags in a line of text, made if they are new.
     *
     * Split on commas, because a tag is allowed to have a space in it and
     * "urgent legal" should not become two.
     *
     * @return Collection<int, Tag>
     */
    public static function parse(string $input): Collection
    {
        return collect(explode(',', $input))
            ->map(fn (string $name) => Tag::named($name))
            ->filter()
            ->unique('id')
            ->take(self::MAX)
            ->values();
    }

    /**
     * What goes back in the box when the form is drawn again.
     */
    public static function toInput(Task $task): string
    {
        return $task->tags->pluck('name')->implode(', ');
    }

    /**
     * The filter token a card carries, pipe-wrapped so "|legal|" cannot match
     * "legal-hold".
     */
    public static function tokens(Task $task): string
    {
        return '|'.$task->tags->pluck('slug')->implode('|').'|';
    }
}
