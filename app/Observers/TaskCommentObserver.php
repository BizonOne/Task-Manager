<?php

namespace App\Observers;

use App\Models\TaskActivity;
use App\Models\TaskComment;

/**
 * Comments are part of a task's history, so posting or removing one leaves a
 * trace even after the comment itself is gone.
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
}
