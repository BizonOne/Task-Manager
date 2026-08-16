<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesTasks;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Check a step off, or put it back.
 */
class SetChecklistItem extends Tool
{
    use ResolvesTasks;

    protected string $description = 'Mark a checklist item done or not done. Refer to the item by its '
        .'id (from get-task or add-checklist-item) or by its exact wording.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL.')
                ->required(),
            'item' => $schema->string()
                ->description('The checklist item: its id, or its exact text.')
                ->required(),
            'done' => $schema->boolean()
                ->description('true to check it off, false to reopen it. Defaults to true.'),
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

        $reference = trim((string) $request->get('item'));

        $item = ctype_digit($reference)
            ? $task->checklistItems()->whereKey((int) $reference)->first()
            : null;

        if ($item === null) {
            $matches = $task->checklistItems()->get()
                ->filter(fn (ChecklistItem $i) => strcasecmp(trim($i->name), $reference) === 0);

            if ($matches->count() > 1) {
                return Response::error('Several items are worded exactly like that — use the id instead: '
                    .$matches->map(fn ($i) => '#'.$i->id)->implode(', ').'.');
            }

            $item = $matches->first();
        }

        if ($item === null) {
            $listed = $task->checklistItems()->get();

            return Response::error('No checklist item matches "'.$reference.'". This task has: '
                .($listed->isEmpty()
                    ? 'no items yet.'
                    : $listed->map(fn ($i) => '#'.$i->id.' “'.$i->name.'”')->implode(', ').'.'));
        }

        $done = $request->has('done') ? $request->boolean('done') : true;

        // update() rides through the observer, so the tick lands in the
        // task history like any other.
        $item->update(['completed' => $done ? 1 : 0]);

        return Response::structured([
            'item_id' => $item->id,
            'name' => $item->name,
            'done' => $done,
            'remaining' => $task->checklistItems()->where('completed', 0)->count(),
        ]);
    }
}
