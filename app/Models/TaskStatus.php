<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * An admin-manageable task status (a column on the task board).
 *
 * `tasks.status` stores this model's `key`, so statuses can be renamed,
 * recoloured, reordered and added without touching task rows.
 */
class TaskStatus extends Model
{
    private const CACHE_KEY = 'task_statuses.ordered';

    protected $fillable = [
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
        // Exactly one status is the default for new tasks.
        static::saved(function (self $status) {
            if ($status->is_default) {
                static::where('id', '!=', $status->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            static::forgetCached();
        });

        static::deleted(fn () => static::forgetCached());
    }

    public static function forgetCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Every status in display order. Falls back to the built-in defaults if the
     * table is missing or empty, so the app never renders a board with no
     * columns.
     *
     * @return Collection<int, TaskStatus>
     */
    public static function ordered(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                $statuses = static::query()->orderBy('sort_order')->orderBy('id')->get();
            } catch (\Throwable) {
                $statuses = collect();
            }

            if ($statuses->isEmpty()) {
                $statuses = collect(static::defaults())->map(fn (array $row) => new static($row));
            }

            return $statuses;
        });
    }

    /**
     * Status keys, in display order.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return static::ordered()->pluck('key')->all();
    }

    /**
     * key => label, for select inputs.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::ordered()->pluck('label', 'key')->all();
    }

    /**
     * A validation rule listing the currently valid status keys.
     */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', static::keys());
    }

    public static function find_by_key(string $key): ?self
    {
        return static::ordered()->firstWhere('key', $key);
    }

    /**
     * The key new tasks start in.
     */
    public static function defaultKey(): string
    {
        $statuses = static::ordered();

        return ($statuses->firstWhere('is_default', true) ?? $statuses->first())?->key ?? 'to_do';
    }

    /**
     * Keys that mean "finished", for progress and reporting queries.
     *
     * @return array<int, string>
     */
    public static function completedKeys(): array
    {
        return static::ordered()->where('is_completed', true)->pluck('key')->all();
    }

    /**
     * Human label for a stored key, falling back to a prettified key so a task
     * left on a deleted status still renders sensibly.
     */
    public static function labelFor(?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        return static::find_by_key($key)?->label
            ?? ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Palette entry for a stored key: hex colours for the front-end board.
     *
     * @return array{bg: string, text: string, dot: string}
     */
    public static function paletteFor(?string $key): array
    {
        $color = static::find_by_key($key)?->color ?? 'gray';

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
