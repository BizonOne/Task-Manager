<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A word somebody wrote on a task.
 */
class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    public $timestamps = true;

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_tag');
    }

    /**
     * The tag by that name, made if this is the first time anyone used it.
     *
     * Matched on the slug, so "Urgent Legal" and "urgent-legal" are one tag
     * rather than two that look identical on a board.
     */
    public static function named(string $name): ?self
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $slug = Str::slug($name);

        if ($name === '' || $slug === '') {
            return null;
        }

        return static::firstOrCreate(
            ['slug' => Str::limit($slug, 60, '')],
            ['name' => Str::limit($name, 60, '')],
        );
    }

    /**
     * A colour, decided by the name so the same tag looks the same everywhere.
     *
     * @return array{bg: string, text: string}
     */
    public function palette(): array
    {
        $palette = [
            ['bg' => '#ede9fe', 'text' => '#5b21b6'],
            ['bg' => '#dbeafe', 'text' => '#1d4ed8'],
            ['bg' => '#dcfce7', 'text' => '#15803d'],
            ['bg' => '#fef3c7', 'text' => '#b45309'],
            ['bg' => '#fee2e2', 'text' => '#b91c1c'],
            ['bg' => '#fce7f3', 'text' => '#be185d'],
            ['bg' => '#ccfbf1', 'text' => '#0f766e'],
        ];

        return $palette[crc32($this->slug) % count($palette)];
    }
}
