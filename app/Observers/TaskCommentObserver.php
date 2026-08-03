<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskCommentedNotification;
use App\Support\MentionParser;
use App\Support\Notifier;
use Illuminate\Support\Collection;

/**
 * Comments are part of a task's history, so posting or removing one leaves a
 * trace even after the comment itself is gone — and everyone working on the
 * task hears about it.
 */
class TaskCommentObserver
{
    public function created(TaskComment $comment): void
    {
        if ($comment->task === null) {
            return;
        }

        TaskActivity::record($comment->task, TaskActivity::EVENT_COMMENTED, [
            'user_id' => $comment->user_id,
            'meta' => ['comment_id' => $comment->id],
        ]);

        $this->notify($comment->task, $comment);
    }

    public function deleted(TaskComment $comment): void
    {
        if ($comment->task === null) {
            return;
        }

        // Keep the trace: the timeline should show that something was removed
        // rather than silently losing the entry.
        TaskActivity::record($comment->task, TaskActivity::EVENT_COMMENT_DELETED, [
            'meta' => ['comment_id' => $comment->id, 'author' => $comment->user?->name],
        ]);
    }

    /**
     * Two notifications, and nobody gets both: the people named in the comment
     * are told they were named, everybody else working on the task is told the
     * discussion moved.
     */
    private function notify(Task $task, TaskComment $comment): void
    {
        if ($comment->user === null) {
            return;
        }

        // Against the plain text, so an '@' inside a link's href never
        // reads as a mention.
        $mentioned = MentionParser::resolve($comment->plain_body, $task->participants())
            ->reject(fn (User $user) => $user->id === $comment->user_id);

        foreach ($mentioned as $user) {
            Notifier::send($user, new MentionedInCommentNotification($comment));
        }

        $mentionedIds = $mentioned->pluck('id')->all();

        foreach ($this->followers($task, $comment) as $user) {
            if (! in_array($user->id, $mentionedIds, true)) {
                Notifier::send($user, new TaskCommentedNotification($comment));
            }
        }
    }

    /**
     * Who is following this discussion: the task's owner, the people assigned
     * to it, and anyone who has already commented.
     *
     * Deliberately *not* the whole project team — a person who has never
     * touched a task does not want every comment on it in their inbox, and a
     * notification nobody wants is one they learn to ignore.
     *
     * @return Collection<int, User>
     */
    private function followers(Task $task, TaskComment $comment): Collection
    {
        $task->loadMissing(['user', 'assignees']);

        // Plucking the ids and uniquing them in PHP, rather than asking the
        // database for DISTINCT: the comments relation carries an "oldest()"
        // ordering, and MySQL rejects DISTINCT ordered by a column that is not
        // selected. SQLite allows it, so this only ever failed in production.
        $earlierAuthorIds = $task->comments()
            ->where('id', '!=', $comment->id)
            ->pluck('user_id')
            ->unique()
            ->values();

        $earlierAuthors = $earlierAuthorIds->isEmpty()
            ? collect()
            : User::whereIn('id', $earlierAuthorIds)->get();

        return collect([$task->user])
            ->merge($task->assignees)
            ->merge($earlierAuthors)
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $comment->user_id)
            ->values();
    }
}
