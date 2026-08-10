<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\ProjectField;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The context a task lives in: the board, the fields, the people.
 */
class GetProject extends Tool
{
    protected string $description = 'Read one project: its board columns, the custom fields it keeps '
        .'on tasks, its members, and task counts per status. Accepts a project id, slug or name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Project id, slug or name.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $reference = trim((string) $request->get('project'));

        $project = ctype_digit($reference)
            ? Project::find((int) $reference)
            : Project::where('slug', $reference)->orWhere('name', $reference)->first();

        if (! $project || ! $project->isAccessibleBy($user)) {
            return Response::error('No project matches that reference, or it is not visible to you.');
        }

        $project->load('user', 'users', 'fields');

        $counts = $project->tasks()->active()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'owner' => $project->user?->only('id', 'name'),
            'members' => $project->users->map->only('id', 'name')->values(),
            'board_columns' => TaskStatus::ordered($project->id)->map(fn (TaskStatus $s) => [
                'key' => $s->key,
                'label' => $s->label,
                'counts_as_finished' => (bool) $s->is_completed,
                'open_tasks' => (int) ($counts[$s->key] ?? 0),
            ])->values(),
            'fields' => $project->fields->map(fn (ProjectField $field) => [
                'name' => $field->name,
                'type' => $field->type,
                'choices' => $field->choices(),
            ])->values(),
            'url' => route('projects.tasks.index', $project),
        ]);
    }
}
