<?php

namespace App\Support\Reports;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One filtered view of the task list, and everything the reports page and its
 * exports are built from.
 *
 * The screen, the spreadsheet and the PDF all read from here, so a person
 * exporting what they are looking at gets what they are looking at — the
 * numbers cannot disagree between the page and the file.
 */
class TaskReport
{
    /**
     * How many rows the page draws. The exports carry everything — a browser
     * table with ten thousand rows helps nobody, and quietly cutting it would
     * be worse than saying so.
     */
    public const TABLE_LIMIT = 500;

    public const DATE_FIELDS = [
        'created_at' => 'Created',
        'due_date' => 'Due',
        'updated_at' => 'Last updated',
        'completed_at' => 'Completed',
        'archived_at' => 'Archived',
    ];

    /**
     * Whether archived work is in scope. A report is an account of what was
     * done, so "all" is the default — filing a task away does not un-do it.
     */
    public const ARCHIVE_STATES = [
        'all' => 'Live and archived',
        'active' => 'Live only',
        'archived' => 'Archived only',
    ];

    /** @var array<string, mixed> */
    private array $filters;

    private ?Collection $cachedTasks = null;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private User $viewer, array $filters = [])
    {
        $this->filters = $this->normalise($filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    public function filter(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }

    /**
     * Whether anything at all was narrowed down — used to tell "no results"
     * apart from "nothing here yet".
     */
    public function isFiltered(): bool
    {
        foreach (['project_id', 'user_id', 'status', 'priority', 'from', 'to', 'search'] as $key) {
            if (! empty($this->filters[$key])) {
                return true;
            }
        }

        return $this->filters['archive'] !== 'all';
    }

    public function query(): Builder
    {
        $query = Task::query()
            ->visibleTo($this->viewer)
            ->with(['project', 'user']);

        if ($projects = $this->filters['project_id']) {
            $query->whereIn('project_id', $projects);
        }

        if ($owners = $this->filters['user_id']) {
            $query->whereIn('user_id', $owners);
        }

        if ($statuses = $this->filters['status']) {
            $query->whereIn('status', $statuses);
        }

        if ($priorities = $this->filters['priority']) {
            $query->whereIn('priority', $priorities);
        }

        if ($search = $this->filters['search']) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        match ($this->filters['archive']) {
            'active' => $query->active(),
            'archived' => $query->archived(),
            default => null,
        };

        $field = $this->filters['date_field'];
        if ($from = $this->filters['from']) {
            $query->whereDate($field, '>=', $from);
        }
        if ($to = $this->filters['to']) {
            $query->whereDate($field, '<=', $to);
        }

        return $query->orderByDesc('id');
    }

    /**
     * @return Collection<int, Task>
     */
    public function tasks(): Collection
    {
        return $this->cachedTasks ??= $this->query()->get();
    }

    /**
     * The headline numbers.
     *
     * @return array<string, int|float|null>
     */
    public function summary(): array
    {
        $tasks = $this->tasks();
        $completedKeys = TaskStatus::completedKeys();
        $today = CarbonImmutable::today();

        $done = $tasks->filter(fn (Task $t) => in_array($t->status, $completedKeys, true));
        $overdue = $tasks->filter(fn (Task $t) => $t->due_date
            && ! in_array($t->status, $completedKeys, true)
            && CarbonImmutable::parse($t->due_date)->lt($today));

        return [
            'total' => $tasks->count(),
            'completed' => $done->count(),
            'open' => $tasks->count() - $done->count(),
            'overdue' => $overdue->count(),
            'completion_rate' => $tasks->isEmpty() ? 0 : (int) round($done->count() / $tasks->count() * 100),
            'estimated_hours' => round((float) $tasks->sum(fn (Task $t) => (float) $t->estimated_hours), 2),
        ];
    }

    /**
     * Counts per status, in the order the statuses are configured.
     *
     * @return Collection<int, array{label: string, key: string, count: int, colour: array<string,string>}>
     */
    public function byStatus(): Collection
    {
        $counts = $this->tasks()->countBy('status');

        return TaskStatus::ordered()
            ->map(fn (TaskStatus $status) => [
                'key' => $status->key,
                'label' => $status->label,
                'count' => $counts[$status->key] ?? 0,
                'colour' => TaskStatus::paletteFor($status->key),
            ])
            // A status nobody used is noise on a report.
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, count: int, completed: int, rate: int}>
     */
    public function byAssignee(): Collection
    {
        return $this->breakdown(fn (Task $t) => $t->user?->name ?? 'Unassigned');
    }

    /**
     * @return Collection<int, array{label: string, count: int, completed: int, rate: int}>
     */
    public function byProject(): Collection
    {
        return $this->breakdown(fn (Task $t) => $t->project?->name ?? 'No project');
    }

    /**
     * @return Collection<int, array{label: string, count: int, completed: int, rate: int}>
     */
    public function byPriority(): Collection
    {
        return $this->breakdown(fn (Task $t) => ucfirst((string) $t->priority))
            // High first: it is what a person scans for.
            ->sortBy(fn (array $row) => array_search($row['label'], ['High', 'Medium', 'Low'], true))
            ->values();
    }

    /**
     * A one-line description of what was filtered, for the export headers —
     * a spreadsheet with no idea what it covers is a spreadsheet nobody trusts.
     */
    public function describe(): string
    {
        $parts = [];

        if ($projects = $this->names(Project::class, $this->filters['project_id'], 'name')) {
            $parts[] = 'Projects: '.$projects;
        }
        if ($people = $this->names(User::class, $this->filters['user_id'], 'name')) {
            $parts[] = 'Assignees: '.$people;
        }
        if ($statuses = $this->filters['status']) {
            $parts[] = 'Status: '.implode(', ', array_map(TaskStatus::labelFor(...), $statuses));
        }
        if ($priorities = $this->filters['priority']) {
            $parts[] = 'Priority: '.implode(', ', array_map('ucfirst', $priorities));
        }
        if ($this->filters['from'] || $this->filters['to']) {
            $label = self::DATE_FIELDS[$this->filters['date_field']] ?? 'Created';
            $parts[] = $label.' between '
                .($this->filters['from'] ?: 'the beginning')
                .' and '.($this->filters['to'] ?: 'today');
        }
        if ($search = $this->filters['search']) {
            $parts[] = 'Title contains "'.$search.'"';
        }
        if ($this->filters['archive'] !== 'all') {
            $parts[] = self::ARCHIVE_STATES[$this->filters['archive']];
        }

        return $parts === [] ? 'All tasks you have access to' : implode(' · ', $parts);
    }

    /**
     * A filename stem that says what is inside without being unwieldy.
     */
    public function filename(): string
    {
        $stamp = CarbonImmutable::now()->format('Y-m-d');

        return 'task-report-'.$stamp;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function names(string $model, array $ids, string $column): ?string
    {
        if ($ids === []) {
            return null;
        }

        return $model::whereIn('id', $ids)->pluck($column)->implode(', ') ?: null;
    }

    /**
     * @return Collection<int, array{label: string, count: int, completed: int, rate: int}>
     */
    private function breakdown(callable $key): Collection
    {
        $completedKeys = TaskStatus::completedKeys();

        return $this->tasks()
            ->groupBy($key)
            ->map(function (Collection $group, string $label) use ($completedKeys) {
                $done = $group->filter(fn (Task $t) => in_array($t->status, $completedKeys, true))->count();

                return [
                    'label' => $label,
                    'count' => $group->count(),
                    'completed' => $done,
                    'rate' => $group->isEmpty() ? 0 : (int) round($done / $group->count() * 100),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalise(array $filters): array
    {
        $list = function (mixed $value): array {
            return collect(is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]))
                ->filter(fn ($v) => $v !== '' && $v !== null)
                ->values()
                ->all();
        };

        $dateField = $filters['date_field'] ?? 'created_at';

        return [
            'project_id' => $list($filters['project_id'] ?? []),
            'user_id' => $list($filters['user_id'] ?? []),
            'status' => $list($filters['status'] ?? []),
            'priority' => $list($filters['priority'] ?? []),
            'date_field' => isset(self::DATE_FIELDS[$dateField]) ? $dateField : 'created_at',
            'from' => $this->date($filters['from'] ?? null),
            'to' => $this->date($filters['to'] ?? null),
            'search' => trim((string) ($filters['search'] ?? '')) ?: null,
            'archive' => isset(self::ARCHIVE_STATES[$filters['archive'] ?? ''])
                ? $filters['archive']
                : 'all',
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
