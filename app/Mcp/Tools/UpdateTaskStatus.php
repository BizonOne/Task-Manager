<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesTasks;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Move a task on its board, the way dragging its card would.
 */
class UpdateTaskStatus extends Tool
{
    use ResolvesTasks;

    protected string $description = 'Move a task to another status on its board. Statuses vary per '
        .'project — get_task lists the ones this task\'s board offers, with their keys.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL.')
                ->required(),
            'status' => $schema->string()
                ->description('The status key to move to, e.g. in_progress. Keys come from get_task.')
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

        $status = trim((string) $request->get('status'));
        $offered = TaskStatus::ordered($task->project_id);

        if (! $offered->contains('key', $status)) {
            return Response::error('This board has no status "'.$status.'". It offers: '
                .$offered->map(fn (TaskStatus $s) => $s->key.' ('.$s->label.')')->implode(', ').'.');
        }

        $from = $task->status_label;
        $task->status = $status;
        $task->save();

        return Response::structured([
            'moved' => true,
            'from' => $from,
            'to' => $task->fresh()->status_label,
            'task_url' => route('tasks.show', $task),
        ]);
    }
}
