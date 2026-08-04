<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A column on a task board.
 *
 * `tasks.status` stores this model's `key`, so statuses can be renamed,
 * recoloured, reordered and added without touching task rows.
 *
 * A row with no project is a shared column, used by every project that has
 * not said otherwise. A project that wants to work differently keeps its own
 * set and stops looking at the shared ones — because two teams always do work
 * differently, and neither wants the other's columns on its screen.
 */
class TaskStatus extends Model
{
    private const CACHE_KEY = 'task_statuses.ordered';

    protected $fillable = [
        'project_id',
        'key',
        'label',
        'color',
        'sort_order',
        'is_completed',
        'is_default',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The statuses shipped with the app. Used to seed the table and as a
     * fallback when it has not been seeded yet (e.g. mid-deploy).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'to_do', 'label' => 'To Do', 'color' => 'gray', 'sort_order' => 1, 'is_completed' => false, 'is_default' => true],
            ['key' => 'in_progress', 'label' => 'In Progress', 'color' => 'violet', 'sort_order' => 2, 'is_completed' => false, 'is_default' => false],
            ['key' => 'on_hold', 'label' => 'On Hold', 'color' => 'amber', 'sort_order' => 3, 'is_completed' => false, 'is_default' => false],
            ['key' => 'in_review', 'label' => 'In Review', 'color' => 'blue', 'sort_order' => 4, 'is_completed' => false, 'is_default' => false],
            ['key' => 'completed', 'label' => 'Completed', 'color' => 'green', 'sort_order' => 5, 'is_completed' => true, 'is_default' => false],
        ];
    }

    protected static function booted(): void
    {
        // Exactly one status is the default for new tasks — on each board
        // separately, since each board starts its own work somewhere.
        static::saved(function (self $status) {
            if ($status->is_default) {
                static::where('id', '!=', $status->id)
                    ->where('project_id', $status->project_id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            static::forgetCached();
        });

        static::deleted(fn () => static::forgetCached());
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether this column belongs to one project rather than to everyone.
     */
    public function isPrivate(): bool
    {
        return $this->project_id !== null;
    }

    public static function forgetCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Every row there is, in display order, cached as one lump.
     *
     * One cache entry rather than one per project: the whole table is a few
     * dozen rows, and a single entry is a single thing to forget.
     *
     * @return Collection<int, TaskStatus>
     */
    private static function rows(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                return static::query()->orderBy('sort_order')->orderBy('id')->get();
            } catch (\Throwable) {
                return collect();
            }
        });
    }

    /**
     * The columns of one board, in display order.
     *
     * A project with columns of its own uses those and nothing else; every
     * other project uses the shared set. Falls back to the built-in defaults
     * if the table is missing or empty, so the app never renders a board with
     * no columns at all.
     *
     * @return Collection<int, TaskStatus>
     */
    public static function ordered(?int $projectId = null): Collection
    {
        $rows = static::rows();

        if ($projectId !== null) {
            $own = $rows->where('project_id', $projectId)->values();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        $shared = $rows->whereNull('project_id')->values();

        return $shared->isNotEmpty()
            ? $shared
            : collect(static::defaults())->map(fn (array $row) => new static($row));
    }

    /**
     * Whether this project has taken its board into its own hands.
     */
    public static function isCustomised(?int $projectId): bool
    {
        return $projectId !== null && static::rows()->where('project_id', $projectId)->isNotEmpty();
    }

    /**
     * The columns a board covering several projects has to show.
     *
     * "My tasks" mixes work from everywhere, so it needs the union — a column
     * missing here is a task nobody can see.
     *
     * @param  iterable<int>  $projectIds
     * @return Collection<int, TaskStatus>
     */
    public static function forProjects(iterable $projectIds): Collection
    {
        $columns = collect($projectIds)
            ->map(fn ($id) => static::ordered((int) $id))
            ->flatten(1)
            ->concat(static::ordered());

        return $columns
            ->unique('key')
            ->sortBy([['sort_order', 'asc'], ['label', 'asc']])
            ->values();
    }

    /**
     * Every column anywhere, one per key — for reports and filters that span
     * the whole app.
     *
     * @return Collection<int, TaskStatus>
     */
    public static function everywhere(): Collection
    {
        $rows = static::rows();

        return ($rows->isEmpty() ? static::ordered() : $rows)
            ->unique('key')
            ->sortBy([['sort_order', 'asc'], ['label', 'asc']])
            ->values();
    }

    /**
     * Status keys, in display order.
     *
     * @return array<int, string>
     */
    public static function keys(?int $projectId = null): array
    {
        return static::ordered($projectId)->pluck('key')->all();
    }

    /**
     * key => label, for select inputs.
     *
     * @return array<string, string>
     */
    public static function options(?int $projectId = null): array
    {
        return static::ordered($projectId)->pluck('label', 'key')->all();
    }

    /**
     * A validation rule listing the currently valid status keys.
     */
    public static function validationRule(?int $projectId = null): string
    {
        return 'in:'.implode(',', static::keys($projectId));
    }

    /**
     * The column with that key on that board — or, failing that, anywhere.
     *
     * The fallback matters wherever a task is shown out of its own context:
     * the dashboard, a report, a search result. Better the right label from
     * another board than a raw key.
     */
    public static function find_by_key(string $key, ?int $projectId = null): ?self
    {
        return static::ordered($projectId)->firstWhere('key', $key)
            ?? static::rows()->firstWhere('key', $key);
    }

    /**
     * The key new tasks start in.
     */
    public static function defaultKey(?int $projectId = null): string
    {
        $statuses = static::ordered($projectId);

        return ($statuses->firstWhere('is_default', true) ?? $statuses->first())?->key ?? 'to_do';
    }

    /**
     * Keys that mean "finished", for progress and reporting queries.
     *
     * @return array<int, string>
     */
    public static function completedKeys(?int $projectId = null): array
    {
        return static::ordered($projectId)->where('is_completed', true)->pluck('key')->all();
    }

    /**
     * Keys that mean "finished" on any board at all.
     *
     * A query spanning projects cannot ask each one in turn, and a completed
     * task left out of "completed" is worse than one wrongly counted in.
     *
     * @return array<int, string>
     */
    public static function completedKeysEverywhere(): array
    {
        return static::everywhere()->where('is_completed', true)->pluck('key')->unique()->values()->all();
    }

    /**
     * Human label for a stored key, falling back to a prettified key so a task
     * left on a deleted status still renders sensibly.
     */
    public static function labelFor(?string $key, ?int $projectId = null): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        return static::find_by_key($key, $projectId)?->label
            ?? ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Palette entry for a stored key: hex colours for the front-end board.
     *
     * @return array{bg: string, text: string, dot: string}
     */
    public static function paletteFor(?string $key, ?int $projectId = null): array
    {
        $color = static::find_by_key($key, $projectId)?->color ?? 'gray';

        return self::palette()[$color] ?? self::palette()['gray'];
    }

    /**
     * The colours an admin can choose from, and the shades each maps to.
     *
     * @return array<string, array{bg: string, text: string, dot: string}>
     */
    public static function palette(): array
    {
        return [
            'gray' => ['bg' => '#f3f4f6', 'text' => '#374151', 'dot' => '#9ca3af'],
            'violet' => ['bg' => '#ede9fe', 'text' => '#5b21b6', 'dot' => '#7c3aed'],
            'blue' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'dot' => '#1d4ed8'],
            'green' => ['bg' => '#dcfce7', 'text' => '#15803d', 'dot' => '#16a34a'],
            'amber' => ['bg' => '#fef3c7', 'text' => '#b45309', 'dot' => '#b45309'],
            'red' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'dot' => '#dc2626'],
            'pink' => ['bg' => '#fce7f3', 'text' => '#be185d', 'dot' => '#db2777'],
            'teal' => ['bg' => '#ccfbf1', 'text' => '#0f766e', 'dot' => '#14b8a6'],
        ];
    }

    /**
     * Map a palette colour onto the colour names Filament badges understand.
     */
    public static function filamentColorFor(?string $key): string
    {
        return match (static::find_by_key($key)?->color) {
            'violet' => 'primary',
            'blue', 'teal' => 'info',
            'green' => 'success',
            'amber' => 'warning',
            'red', 'pink' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Colour options for the admin select: key => human label.
     *
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return collect(array_keys(self::palette()))
            ->mapWithKeys(fn (string $c) => [$c => ucfirst($c)])
            ->all();
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'status', 'key');
    }
}
