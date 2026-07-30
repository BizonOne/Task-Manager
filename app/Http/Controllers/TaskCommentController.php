<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskComment;
use App\Notifications\MentionedInCommentNotification;
use App\Support\MentionParser;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskCommentController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $user = Auth::user();
        abort_unless($task->isAccessibleBy($user), 403);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $comment = $task->comments()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        $this->notifyMentioned($task, $comment);

        return response()->json([
            'success' => true,
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user_name' => $user->name,
                'initials' => $this->initials($user->name),
                'created_at' => $comment->created_at->diffForHumans(),
                'is_author' => true,
            ],
        ]);
    }

    public function destroy(TaskComment $comment)
    {
        $user = Auth::user();
        $task = $comment->task;

        // The comment author, the task owner, the project owner or a super
        // admin may delete it.
        $canDelete = $user->isSuperAdmin()
            || $comment->user_id === $user->id
            || $task->user_id === $user->id
            || $task->project?->user_id === $user->id;

        abort_unless($canDelete, 403);

        $comment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Notify every participant mentioned in the comment, except the author.
     */
    private function notifyMentioned(Task $task, TaskComment $comment): void
    {
        $mentioned = MentionParser::resolve($comment->body, $task->participants())
            ->reject(fn ($user) => $user->id === $comment->user_id);

        foreach ($mentioned as $user) {
            Notifier::send($user, new MentionedInCommentNotification($comment));
        }
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');

        return mb_strtoupper($initials ?: 'U');
    }
}
