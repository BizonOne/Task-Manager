<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * The assistant's hands.
 *
 * Every call is executed on behalf of one user and re-checks that user's
 * access — the model choosing to call a tool is a request, never permission.
 * Tool *results* are data: they are handed back to the model as structured
 * values, so text inside a task title can't be mistaken for an instruction.
 */
class AssistantTools
{
    private const MAX_ROWS = 25;

    public function __construct(private readonly User $user) {}

    /**
     * Function declarations advertised to the model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarations(): array
    {
        $statusKeys = implode(', ', TaskStatus::keys());

        return [
            [
                'name' => 'search_tasks',
                'description' => 'Find the tasks the user can see. Use this before answering anything about tasks, workload, deadlines or progress.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Free text matched against title and description.'],
                        'status' => ['type' => 'string', 'description' => "Restrict to one status key. Available: {$statusKeys}."],
                        'overdue' => ['type' => 'boolean', 'description' => 'Only tasks past their due date and not finished.'],
                        'due_within_days' => ['type' => 'integer', 'description' => 'Only tasks due within this many days.'],
                        'priority' => ['type' => 'string', 'description' => 'low, medium or high.'],
                        'project' => ['type' => 'string', 'description' => 'Restrict to a project by name.'],
                        'assigned_to_me' => ['type' => 'boolean', 'description' => 'Only tasks the user owns or is assigned to.'],
                        'include_finished' => ['type' => 'boolean', 'description' => 'Include tasks in a completed status. Defaults to false.'],
                    ],
                ],
            ],
            [
                'name' => 'get_task',
                'description' => 'Full detail for one task: description, checklist, assignees, comments and its change history.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['task_id' => ['type' => 'integer']],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a task in a project. Confirm the details with the user before calling this.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'project' => ['type' => 'string', 'description' => 'Project name. Must be one the user can access.'],
                        'description' => ['type' => 'string'],
                        'priority' => ['type' => 'string', 'description' => 'low, medium or high. Defaults to medium.'],
                        'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                        'status' => ['type' => 'string', 'description' => "One of: {$statusKeys}. Defaults to the board's first column."],
                    ],
                    'required' => ['title', 'project'],
                ],
            ],
            [
                'name' => 'update_task',
                'description' => 'Change a task the user is allowed to manage: status, priority, due date, title or description.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'description' => "One of: {$statusKeys}."],
                        'priority' => ['type' => 'string'],
                        'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or "none" to clear it.'],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'comment_on_task',
                'description' => 'Post a comment in a task discussion, as the user.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['task_id', 'body'],
                ],
            ],
            [
                'name' => 'list_projects',
                'description' => 'Projects the user owns or belongs to, with task counts and progress.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'list_reminders',
                'description' => "The user's reminders.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'overdue' => ['type' => 'boolean'],
                        'include_completed' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'create_reminder',
                'description' => 'Create a reminder for the user.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                        'time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                        'priority' => ['type' => 'string', 'description' => 'low, medium, high or urgent.'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'workspace_summary',
                'description' => 'Counts across the workspace: tasks by status, overdue, due today, projects, reminders. Use for "how am I doing" style questions.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /**
     * Execute a tool call. Never throws: a failure is returned to the model as
     * an error field so it can explain itself instead of the turn collapsing.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $name, array $args): array
    {
        try {
            return match ($name) {
                'search_tasks' => $this->searchTasks($args),
                'get_task' => $this->getTask($args),
                'create_task' => $this->createTask($args),
                'update_task' => $this->updateTask($args),
                'comment_on_task' => $this->commentOnTask($args),
                'list_projects' => $this->listProjects(),
                'list_reminders' => $this->listReminders($args),
                'create_reminder' => $this->createReminder($args),
                'workspace_summary' => $this->workspaceSummary(),
                default => ['error' => "Unknown tool: {$name}"],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    /**
     * Base query: only tasks this user may see. A super admin oversees all.
     */
    private function visibleTasks()
    {
        return Task::query()->when(! $this->user->isSuperAdmin(), function ($q) {
            $q->where(function ($scope) {
                $scope->where('user_id', $this->user->id)
                    ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $this->user->id))
                    ->orWhereHas('project', function ($p) {
                        $p->where('user_id', $this->user->id)
                            ->orWhereHas('users', fn ($u) => $u->where('users.id', $this->user->id));
                    });
            });
        });
    }

    private function searchTasks(array $args): array
    {
        $query = $this->visibleTasks()->with('project');

        if (filled($args['query'] ?? null)) {
            $term = '%'.$args['query'].'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        if (filled($args['status'] ?? null)) {
            $query->where('status', $args['status']);
        } elseif (! ($args['include_finished'] ?? false)) {
            $query->notCompleted();
        }

        if ($args['overdue'] ?? false) {
            $query->notCompleted()->whereNotNull('due_date')->whereDate('due_date', '<', today());
        }

        if (filled($args['due_within_days'] ?? null)) {
            $query->whereNotNull('due_date')
                ->whereDate('due_date', '<=', today()->addDays((int) $args['due_within_days']));
        }

        if (filled($args['priority'] ?? null)) {
            $query->where('priority', $args['priority']);
        }

        if (filled($args['project'] ?? null)) {
            $query->whereHas('project', fn ($p) => $p->where('name', 'like', '%'.$args['project'].'%'));
        }

        if ($args['assigned_to_me'] ?? false) {
            $query->where(function ($q) {
                $q->where('user_id', $this->user->id)
                    ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $this->user->id));
            });
        }

        $tasks = $query->orderByRaw('due_date is null')  // portable: nulls last
            ->orderBy('due_date')
            ->limit(self::MAX_ROWS)
            ->get();

        return [
            'count' => $tasks->count(),
            'truncated' => $tasks->count() >= self::MAX_ROWS,
            'tasks' => $tasks->map(fn (Task $t) => $this->taskSummary($t))->all(),
        ];
    }

    private function getTask(array $args): array
    {
        $task = $this->visibleTasks()->find($args['task_id'] ?? 0);

        if ($task === null) {
            return ['error' => 'No task with that id is visible to you.'];
        }

        $task->load(['assignees', 'checklistItems', 'comments.user', 'activities.user']);

        return [
            'task' => $this->taskSummary($task) + [
                'description' => Str::limit(strip_tags((string) $task->description), 1500),
                'assignees' => $task->assignees->pluck('name')->all(),
                'checklist' => $task->checklistItems->map(fn ($i) => [
                    'name' => $i->name,
                    'done' => (bool) $i->completed,
                ])->all(),
                'comments' => $task->comments->map(fn ($c) => [
                    'author' => $c->user?->name,
                    'at' => $c->created_at?->toDateTimeString(),
                    'body' => Str::limit($c->body, 600),
                ])->all(),
                'history' => $task->activities->take(-20)->map(fn ($a) => [
                    'at' => $a->created_at?->toDateTimeString(),
                    'who' => $a->actor_name,
                    'what' => $a->description,
                ])->values()->all(),
            ],
        ];
    }

    private function createTask(array $args): array
    {
        $project = $this->findProject($args['project'] ?? '');

        if ($project === null) {
            return ['error' => 'No project matching "'.($args['project'] ?? '').'" is available to you.'];
        }

        if (! $project->isAccessibleBy($this->user)) {
            return ['error' => 'You do not have access to that project.'];
        }

        $status = in_array($args['status'] ?? '', TaskStatus::keys(), true)
            ? $args['status']
            : TaskStatus::defaultKey();

        $task = Task::create([
            'user_id' => $this->user->id,
            'project_id' => $project->id,
            'title' => Str::limit((string) ($args['title'] ?? 'Untitled'), 255, ''),
            'description' => $args['description'] ?? null,
            'priority' => in_array($args['priority'] ?? '', ['low', 'medium', 'high'], true) ? $args['priority'] : 'medium',
            'status' => $status,
            'due_date' => $this->parseDate($args['due_date'] ?? null),
        ]);

        return ['created' => $this->taskSummary($task->fresh('project'))];
    }

    private function updateTask(array $args): array
    {
        $task = $this->visibleTasks()->find($args['task_id'] ?? 0);

        if ($task === null) {
            return ['error' => 'No task with that id is visible to you.'];
        }

        // Anyone who can see a task may move it along; editing its content
        // needs manage rights, mirroring the web UI.
        $wantsContentChange = array_intersect(array_keys($args), ['title', 'description', 'priority', 'due_date']) !== [];

        if ($wantsContentChange && ! $task->isManageableBy($this->user)) {
            return ['error' => 'You can change this task\'s status but not its details.'];
        }

        $changes = [];

        if (filled($args['status'] ?? null)) {
            if (! in_array($args['status'], TaskStatus::keys(), true)) {
                return ['error' => 'Unknown status. Available: '.implode(', ', TaskStatus::keys())];
            }
            $changes['status'] = $args['status'];
        }

        foreach (['title', 'description', 'priority'] as $field) {
            if (filled($args[$field] ?? null)) {
                $changes[$field] = $args[$field];
            }
        }

        if (array_key_exists('due_date', $args)) {
            $changes['due_date'] = strtolower((string) $args['due_date']) === 'none'
                ? null
                : $this->parseDate($args['due_date']);
        }

        if ($changes === []) {
            return ['error' => 'Nothing to change.'];
        }

        $task->update($changes);

        return ['updated' => $this->taskSummary($task->fresh('project'))];
    }

    private function commentOnTask(array $args): array
    {
        $task = $this->visibleTasks()->find($args['task_id'] ?? 0);

        if ($task === null || ! $task->isAccessibleBy($this->user)) {
            return ['error' => 'No task with that id is visible to you.'];
        }

        $body = trim((string) ($args['body'] ?? ''));

        if ($body === '') {
            return ['error' => 'The comment is empty.'];
        }

        $comment = $task->comments()->create([
            'user_id' => $this->user->id,
            'body' => Str::limit($body, 5000, ''),
        ]);

        return ['posted' => ['task_id' => $task->id, 'comment_id' => $comment->id]];
    }

    // ── Projects, reminders, summary ─────────────────────────────────────

    private function visibleProjects()
    {
        return Project::query()->when(! $this->user->isSuperAdmin(), function ($q) {
            $q->where(function ($scope) {
                $scope->where('user_id', $this->user->id)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $this->user->id));
            });
        });
    }

    private function findProject(string $name): ?Project
    {
        if (trim($name) === '') {
            return null;
        }

        return $this->visibleProjects()
            ->where('name', 'like', '%'.trim($name).'%')
            ->first();
    }

    private function listProjects(): array
    {
        $projects = $this->visibleProjects()->withCount('tasks')->limit(self::MAX_ROWS)->get();

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(fn (Project $p) => [
                'name' => $p->name,
                'status' => $p->status,
                'tasks' => $p->tasks_count,
                'open_tasks' => $p->tasks()->notCompleted()->count(),
                'deadline' => $p->end_date?->toDateString(),
                'url' => route('projects.show', $p),
            ])->all(),
        ];
    }

    private function listReminders(array $args): array
    {
        $query = Reminder::where('user_id', $this->user->id);

        if ($args['overdue'] ?? false) {
            $query->overdue();
        } elseif (! ($args['include_completed'] ?? false)) {
            $query->active();
        }

        $reminders = $query->orderBy('date')->limit(self::MAX_ROWS)->get();

        return [
            'count' => $reminders->count(),
            'reminders' => $reminders->map(fn (Reminder $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'date' => $r->date?->toDateString(),
                'time' => $r->time,
                'priority' => $r->priority,
                'completed' => (bool) $r->is_completed,
            ])->all(),
        ];
    }

    private function createReminder(array $args): array
    {
        $reminder = Reminder::create([
            'user_id' => $this->user->id,
            'title' => Str::limit((string) ($args['title'] ?? 'Reminder'), 255, ''),
            'description' => $args['description'] ?? null,
            'date' => $this->parseDate($args['date'] ?? null) ?? today()->toDateString(),
            'time' => preg_match('/^\d{1,2}:\d{2}$/', (string) ($args['time'] ?? '')) ? $args['time'] : null,
            'priority' => in_array($args['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $args['priority'] : 'medium',
        ]);

        return ['created' => ['id' => $reminder->id, 'title' => $reminder->title, 'date' => $reminder->date?->toDateString()]];
    }

    private function workspaceSummary(): array
    {
        $tasks = $this->visibleTasks();

        $byStatus = [];
        foreach (TaskStatus::ordered() as $status) {
            $byStatus[$status->label] = (clone $tasks)->where('status', $status->key)->count();
        }

        return [
            'today' => today()->toDateString(),
            'tasks_by_status' => $byStatus,
            'overdue_tasks' => (clone $tasks)->notCompleted()->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'due_today' => (clone $tasks)->notCompleted()->whereDate('due_date', today())->count(),
            'projects' => $this->visibleProjects()->count(),
            'active_reminders' => Reminder::where('user_id', $this->user->id)->active()->count(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function taskSummary(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => TaskStatus::labelFor($task->status),
            'status_key' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date ? Carbon::parse($task->due_date)->toDateString() : null,
            'overdue' => $task->due_date && Carbon::parse($task->due_date)->isPast() && ! $task->isCompleted(),
            'project' => $task->project?->name,
            'url' => route('tasks.show', $task->id),
        ];
    }

    private function parseDate(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
