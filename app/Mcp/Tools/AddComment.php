<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesTasks;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Report back on a task, in the discussion where people will look.
 */
class AddComment extends Tool
{
    protected string $description = 'Post a comment on a task. Plain text; paragraphs are kept. '
        .'The comment appears under the name of the person whose token you hold, '
        .'and the task\'s participants are notified the same way as for any comment.';

    use ResolvesTasks;

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL.')
                ->required(),
            'text' => $schema->string()
                ->description('The comment, plain text, up to 5000 characters.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->resolveTask((string) $request->get('task'), $user);

        if (! $task) {
            return Response::error('No task matches that reference, or it is not visible to you.');
        }

        $text = trim((string) $request->get('text'));

        if ($text === '') {
            return Response::error('Write something before posting.');
        }

        if (mb_strlen($text) > 5000) {
            return Response::error('That comment is too long — the limit is 5000 characters.');
        }

        // Comments are stored as editor HTML everywhere else, so plain text
        // becomes escaped paragraphs — nothing an agent writes is markup.
        $body = Str::of($text)
            ->split('/\R{2,}/')
            ->map(fn ($paragraph) => '<p>'.nl2br(e(trim((string) $paragraph))).'</p>')
            ->implode('');

        // Who hears about it is the observer's job, same as the web form.
        $comment = $task->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
        ]);

        return Response::structured([
            'posted' => true,
            'comment_id' => $comment->id,
            'as' => $user->name,
            'task_url' => route('tasks.show', $task),
        ]);
    }
}
