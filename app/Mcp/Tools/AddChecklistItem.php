<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesTasks;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Put a step on a task's checklist.
 */
class AddChecklistItem extends Tool
{
    use ResolvesTasks;

    protected string $description = 'Add an item to a task\'s checklist. It appears in the task history '
        .'under the name of the person whose credential you hold.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL.')
                ->required(),
            'name' => $schema->string()
                ->description('The checklist item, up to 255 characters.')
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

        $name = trim((string) $request->get('name'));

        if ($name === '' || mb_strlen($name) > 255) {
            return Response::error('The item must be 1–255 characters.');
        }

        $item = $task->checklistItems()->create(['name' => $name]);

        return Response::structured([
            'added' => true,
            'item_id' => $item->id,
            'checklist' => $task->checklistItems()->get()
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'done' => (bool) $i->completed])
                ->values(),
        ]);
    }
}
