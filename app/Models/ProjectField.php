<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Something a project has decided to record on every task in it.
 *
 * A field belongs to one project and nowhere else: that is the whole point.
 * The statuses are shared by every board in the app, and adding a column for
 * one team puts it on everybody's screen — a field cannot do that.
 */
class ProjectField extends Model
{
    public const TYPE_SELECT = 'select';

    public const TYPE_MULTI = 'multi_select';

    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'name',
        'type',
        'options',
        'sort_order',
        'show_on_card',
    ];

    protected $casts = [
        'options' => 'array',
        'show_on_card' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_SELECT => 'Pick one',
            self::TYPE_MULTI => 'Pick several',
            self::TYPE_TEXT => 'Free text',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $field) {
            $field->key = $field->key ?: static::uniqueKey($field->project_id, $field->name);
            $field->sort_order = $field->sort_order ?: (int) static::where('project_id', $field->project_id)->max('sort_order') + 1;
        });
    }

    /**
     * A slug that is stable for the life of the field.
     *
     * Deliberately not regenerated on rename: values, filters and imports all
     * point at it, and renaming "Acquirer" to "Acquiring bank" must not lose
     * what every task already says.
     */
    private static function uniqueKey(?int $projectId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'field';
        $key = $base;
        $suffix = 2;

        while (static::where('project_id', $projectId)->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function values()
    {
        return $this->hasMany(TaskFieldValue::class);
    }

    /**
     * Whether this field is answered by choosing from a list.
     */
    public function isChoice(): bool
    {
        return in_array($this->type, [self::TYPE_SELECT, self::TYPE_MULTI], true);
    }

    public function isMultiple(): bool
    {
        return $this->type === self::TYPE_MULTI;
    }

    /**
     * The choices, cleaned of the blank lines people leave behind.
     *
     * @return array<int, string>
     */
    public function choices(): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->options), fn ($o) => $o !== ''));
    }

    /**
     * Turn whatever the form sent into the list this field stores.
     *
     * @return array<int, string>
     */
    public function normalise(mixed $input): array
    {
        $values = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) $input),
            fn (string $v) => $v !== ''
        ));

        if (! $this->isChoice()) {
            // Free text is one answer however many boxes the browser sent.
            return $values === [] ? [] : [implode(' ', $values)];
        }

        // A choice that is no longer on the list is not an answer. Options get
        // edited, and a task must never claim a value the field cannot offer.
        $values = array_values(array_intersect($values, $this->choices()));

        return $this->isMultiple() ? $values : array_slice($values, 0, 1);
    }
}
