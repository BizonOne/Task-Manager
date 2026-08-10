<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesTasks;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The whole task, the way its page shows it to a person.
 */
class GetTask extends Tool
{
    use ResolvesTasks;

    protected string $description = 'Read one task in full: description, status, priority, assignees, '
        .'project fields, tags, checklist, discussion and the list of attachments. '
        .'Accepts a task id, a TASK-0078 key, or a task URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, key (TASK-0078) or URL (https://…/tasks/78).')
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

        $task->load('user', 'creator', 'project', 'assignees', 'tags', 'checklistItems',
            'comments.user', 'comments.files', 'files');

        return Response::structured([
            'id' => $task->id,
            'key' => sprintf('TASK-%04d', $task->id),
            'url' => route('tasks.show', $task),
            'title' => $task->title,
            'description' => RichText::toText($task->description),
            'status' => ['key' => $task->status, 'label' => $task->status_label],
            'available_statuses' => TaskStatus::ordered($task->project_id)
                ->map(fn (TaskStatus $s) => ['key' => $s->key, 'label' => $s->label])->values(),
            'priority' => $task->priority,
            // A raw column, not a cast — the rest of the app parses it too.
            'due_date' => $task->due_date ? (string) $task->due_date : null,
            'archived' => $task->isArchived(),
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
            'owner' => $task->user?->only('id', 'name'),
            'created_by' => $task->creator?->only('id', 'name'),
            'assignees' => $task->assignees->map->only('id', 'name')->values(),
            'tags' => $task->tags->pluck('name')->values(),
            'fields' => $task->fieldAnswers()->map(fn (array $a) => [
                'name' => $a['field']->name,
                'value' => $a['value'],
            ])->values(),
            'checklist' => $task->checklistItems->map(fn ($item) => [
                'name' => $item->name,
                'done' => (bool) $item->completed,
            ])->values(),
            'comments' => $task->comments->map(fn ($comment) => [
                'author' => $comment->user?->name,
                'at' => $comment->created_at->toIso8601String(),
                'text' => RichText::toText($comment->body),
                'attachment_ids' => $comment->files->pluck('id')->values(),
            ])->values(),
            'attachments' => $task->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->name,
                'mime_type' => $file->mime_type,
                'size' => $file->readable_size,
                'uploaded_by' => $file->user?->name,
            ])->values(),
            'created_at' => $task->created_at->toIso8601String(),
            'updated_at' => $task->updated_at->toIso8601String(),
        ]);
    }
}
