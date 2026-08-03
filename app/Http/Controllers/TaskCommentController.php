<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Task;
use App\Models\TaskComment;
use App\Support\RichText;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskCommentController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $user = Auth::user();
        abort_unless($task->isAccessibleBy($user), 403);

        $hasAttachments = $request->hasFile('attachments');

        $validated = $request->validate([
            // The editor sends HTML, so the limit has to leave room for the
            // markup; what a person can actually write is the rule below.
            // A file on its own is a perfectly good comment, so the text is
            // only required when nothing is attached.
            'body' => [
                $hasAttachments ? 'nullable' : 'required', 'string', 'max:20000',
                function (string $attribute, mixed $value, callable $fail) use ($hasAttachments): void {
                    if (! $hasAttachments && RichText::isEmpty($value)) {
                        $fail('Write something before posting.');
                    }
                    if (mb_strlen(RichText::toText($value)) > 5000) {
                        $fail('That comment is too long.');
                    }
                },
            ],
            'attachments' => 'sometimes|array|max:10',
            'attachments.*' => Uploads::rule(),
        ]);

        $comment = $task->comments()->create([
            'user_id' => $user->id,
            'body' => $validated['body'] ?? '',
        ]);

        $files = collect($request->file('attachments') ?: [])
            ->map(fn ($upload) => Uploads::store($upload, $user, task: $task, comment: $comment));

        // Who hears about a comment is decided in TaskCommentObserver, so a
        // comment posted from the admin panel notifies the same people as one
        // posted here.

        return response()->json([
            'success' => true,
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user_name' => $user->name,
                'initials' => $this->initials($user->name),
                'created_at' => $comment->created_at->diffForHumans(),
                'is_author' => true,
                'files' => $files->map(fn (File $file) => app(FileController::class)->toJson($file))->values(),
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

        // The rows cascade, but the stored objects would be left behind in the
        // bucket paying rent forever.
        $removedFileIds = $comment->files->pluck('id');
        foreach ($comment->files as $file) {
            Uploads::deleteStored($file);
        }

        $comment->delete();

        return response()->json(['success' => true, 'removed_file_ids' => $removedFileIds]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');

        return mb_strtoupper($initials ?: 'U');
    }
}
