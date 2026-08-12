<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesPeople;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Tags;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * File a task, the way the New Task form does.
 */
class CreateTask extends Tool
{
    use ResolvesPeople;

    protected string $description = 'Create a task in a project you can see. It lands in the board\'s '
        .'first column unless you say otherwise, owned by you unless you name someone. '
        .'The owner is notified exactly as if the task were filed by hand.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Project id, slug or name to file the task in.')
                ->required(),
            'title' => $schema->string()
                ->description('The task title, up to 255 characters.')
                ->required(),
            'description' => $schema->string()
                ->description('Plain-text description; paragraphs are kept.'),
            'priority' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->description('Defaults to medium.'),
            'due_date' => $schema->string()
                ->description('Due date as YYYY-MM-DD.'),
            'assignee' => $schema->string()
                ->description('Who owns it: "me" (default), a name, or an email. Must be on the project.'),
            'tags' => $schema->string()
                ->description('Comma-separated tags, e.g. "urgent, onboarding". New tags are created.'),
            'status' => $schema->string()
                ->description('Status key from get-project. Defaults to the board\'s first column.'),
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

        $title = trim((string) $request->get('title'));

        if ($title === '' || mb_strlen($title) > 255) {
            return Response::error('The title must be 1–255 characters.');
        }

        $owner = $this->resolvePerson((string) ($request->get('assignee') ?: 'me'), $user);

        if ($owner instanceof Collection) {
            return Response::error($this->describePersonMiss((string) $request->get('assignee'), $owner));
        }

        if (! $project->isAccessibleBy($owner)) {
            return Response::error($owner->name.' is not a member of '.$project->name.'.');
        }

        $dueDate = trim((string) $request->get('due_date'));

        if ($dueDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return Response::error('due_date must be YYYY-MM-DD.');
        }

        $status = trim((string) $request->get('status')) ?: TaskStatus::defaultKey($project->id);

        if (! TaskStatus::ordered($project->id)->contains('key', $status)) {
            return Response::error('This board has no status "'.$status.'". It offers: '
                .TaskStatus::ordered($project->id)->map(fn (TaskStatus $s) => $s->key)->implode(', ').'.');
        }

        $priority = (string) ($request->get('priority') ?: 'medium');

        if (! in_array($priority, ['low', 'medium', 'high'], true)) {
            return Response::error('priority must be low, medium or high.');
        }

        // Stored the way the editor stores it: escaped paragraphs, so plain
        // text from an agent can never become markup.
        $description = trim((string) $request->get('description'));
        $body = $description === '' ? null : Str::of($description)
            ->split('/\R{2,}/')
            ->map(fn ($paragraph) => '<p>'.nl2br(e(trim((string) $paragraph))).'</p>')
            ->implode('');

        // Creation runs through the model so the observers do their usual
        // work: the history entry, and the owner hearing about a task filed
        // for them by someone else.
        $task = Task::create([
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'title' => $title,
            'description' => $body,
            'priority' => $priority,
            'status' => $status,
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ]);

        Tags::apply($task, (string) $request->get('tags') ?: null);

        return Response::structured([
            'created' => true,
            'id' => $task->id,
            'key' => sprintf('TASK-%04d', $task->id),
            'url' => route('tasks.show', $task),
            'status' => ['key' => $task->status, 'label' => $task->status_label],
            'owner' => $owner->only('id', 'name'),
        ]);
    }
}
