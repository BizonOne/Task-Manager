<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The board, filtered — what a person sees when they open "My Tasks".
 */
class ListTasks extends Tool
{
    protected string $description = 'List the tasks visible to you, newest first. Filter by project name, '
        .'status key, tag, assignee ("me" or a name), or free-text search over titles. '
        .'Returns summaries; use get_task for the full picture of one task.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project name or id to limit to.'),
            'status' => $schema->string()->description('Status key, e.g. to_do, in_progress.'),
            'tag' => $schema->string()->description('Tag name.'),
            'assignee' => $schema->string()->description('"me", or an assignee name or email.'),
            'search' => $schema->string()->description('Free text matched against task titles.'),
            'include_archived' => $schema->boolean()->description('Also return archived tasks. Off by default.'),
            'limit' => $schema->integer()->description('Maximum tasks to return, 1–100. Default 25.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $query = Task::query()->visibleTo($user)->with(['project', 'assignees', 'tags'])->latest();

        if (! $request->boolean('include_archived')) {
            $query->active();
        }

        if ($project = trim((string) $request->get('project'))) {
            $query->whereHas('project', fn ($q) => ctype_digit($project)
                ? $q->where('projects.id', (int) $project)
                : $q->where('projects.name', 'like', '%'.$project.'%'));
        }

        if ($status = trim((string) $request->get('status'))) {
            $query->where('status', $status);
        }

        if ($tag = trim((string) $request->get('tag'))) {
            $query->whereHas('tags', fn ($q) => $q->where('name', $tag)->orWhere('slug', $tag));
        }

        if ($assignee = trim((string) $request->get('assignee'))) {
            $query->whereHas('assignees', fn ($q) => strcasecmp($assignee, 'me') === 0
                ? $q->where('users.id', $user->id)
                : $q->where(fn ($w) => $w->where('users.name', 'like', '%'.$assignee.'%')
                    ->orWhere('users.email', $assignee)));
        }

        if ($search = trim((string) $request->get('search'))) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $limit = min(max((int) ($request->get('limit') ?: 25), 1), 100);

        $tasks = $query->limit($limit)->get();

        return Response::structured([
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'key' => sprintf('TASK-%04d', $task->id),
                'title' => $task->title,
                'summary' => RichText::toText($task->description, 200),
                'status' => $task->status,
                'status_label' => $task->status_label,
                'priority' => $task->priority,
                'due_date' => $task->due_date ? (string) $task->due_date : null,
                'project' => $task->project?->name,
                'assignees' => $task->assignees->pluck('name')->values(),
                'tags' => $task->tags->pluck('name')->values(),
                'archived' => $task->isArchived(),
                'url' => route('tasks.show', $task),
            ])->values(),
        ]);
    }
}
