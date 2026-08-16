<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesPeople;
use App\Mcp\Concerns\ResolvesTasks;
use App\Models\User;
use App\Support\TaskAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Put someone on a task, or take them off — the same handover the
 * Assignees card performs, notifications and history included.
 */
class AssignTask extends Tool
{
    use ResolvesPeople, ResolvesTasks;

    protected string $description = 'Assign a person to a task, or remove them. Only the task\'s owner '
        .'or a project manager may do this — the same rule as the task page. '
        .'The person is notified as for any assignment.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL.')
                ->required(),
            'person' => $schema->string()
                ->description('"me", a name, or an email. Must be a member of the task\'s project.')
                ->required(),
            'remove' => $schema->boolean()
                ->description('true to take the person off the task instead. Defaults to false.'),
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

        // Reading a task and rearranging who works on it are different
        // powers; this one belongs to the owner and the project's managers.
        if (! $task->isManageableBy($user)) {
            return Response::error('Only the task\'s owner or a project manager may change assignees.');
        }

        $person = $this->resolvePerson((string) $request->get('person'), $user);

        if ($person instanceof Collection) {
            return Response::error($this->describePersonMiss((string) $request->get('person'), $person));
        }

        if ($request->boolean('remove')) {
            $changed = TaskAssignment::detach($task, $person);

            return Response::structured([
                'removed' => $changed,
                'note' => $changed ? null : $person->name.' was not on this task.',
                'assignees' => $task->assignees()->get()->map->only('id', 'name')->values(),
            ]);
        }

        // Someone who cannot see the project would be assigned to a board
        // they cannot open — a handover into a locked room.
        if ($task->project && ! $task->project->isAccessibleBy($person)) {
            return Response::error($person->name.' is not a member of '.$task->project->name.'.');
        }

        $changed = TaskAssignment::attach($task, $person);

        return Response::structured([
            'assigned' => $changed,
            'note' => $changed ? null : $person->name.' was already on this task.',
            'assignees' => $task->assignees()->get()->map->only('id', 'name')->values(),
        ]);
    }
}
