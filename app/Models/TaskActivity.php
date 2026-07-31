<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One entry in a task's history: a field change, an assignment, a comment
 * being posted, or the task being created.
 */
class TaskActivity extends Model
{
    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_UNASSIGNED = 'unassigned';

    public const EVENT_COMMENTED = 'commented';

    public const EVENT_COMMENT_DELETED = 'comment_deleted';

    protected $fillable = [
        'task_id',
        'user_id',
        'event',
        'field',
        'old_value',
        'new_value',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an entry, attributing it to the signed-in user when there is one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(Task $task, string $event, array $attributes = []): self
    {
        return static::create(array_merge([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'event' => $event,
        ], $attributes));
    }

    /**
     * Who to show as the actor. Falls back to "System" for changes made
     * outside a request (console commands, seeders).
     */
    public function getActorNameAttribute(): string
    {
        return $this->user?->name ?? 'System';
    }

    /**
     * A human sentence describing the entry, e.g.
     * "changed status from To Do to In Progress".
     */
    public function getDescriptionAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'created this task',
            self::EVENT_ASSIGNED => 'assigned '.($this->meta['name'] ?? 'someone'),
            self::EVENT_UNASSIGNED => 'unassigned '.($this->meta['name'] ?? 'someone'),
            self::EVENT_COMMENTED => 'commented',
            self::EVENT_COMMENT_DELETED => 'deleted a comment',
            self::EVENT_UPDATED => $this->describeFieldChange(),
            default => $this->event,
        };
    }

    private function describeFieldChange(): string
    {
        $label = $this->fieldLabel();
        $from = $this->displayValue($this->old_value);
        $to = $this->displayValue($this->new_value);

        // Long free text (description) reads badly inline — just note it changed.
        if (in_array($this->field, ['description'], true)) {
            return 'updated the '.$label;
        }

        if ($from === null && $to !== null) {
            return 'set '.$label.' to '.$to;
        }

        if ($from !== null && $to === null) {
            return 'cleared '.$label;
        }

        return 'changed '.$label.' from '.$from.' to '.$to;
    }

    private function fieldLabel(): string
    {
        return match ($this->field) {
            'status' => 'status',
            'priority' => 'priority',
            'due_date' => 'due date',
            'estimated_hours' => 'estimate',
            'project_id' => 'project',
            'user_id' => 'owner',
            'title' => 'title',
            'description' => 'description',
            default => str_replace('_', ' ', (string) $this->field),
        };
    }

    /**
     * Render a stored raw value the way a person expects to read it.
     */
    private function displayValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->field) {
            'status' => TaskStatus::labelFor($value),
            'priority' => ucfirst($value),
            'due_date' => Carbon::parse($value)->format('j M Y'),
            'estimated_hours' => $value.' h',
            'project_id' => Project::find($value)?->name ?? '#'.$value,
            'user_id' => User::find($value)?->name ?? '#'.$value,
            default => '“'.Str::limit($value, 60).'”',
        };
    }

    /**
     * Icon hint for the timeline, kept out of the views.
     */
    public function getIconAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'plus-circle',
            self::EVENT_ASSIGNED => 'person-plus',
            self::EVENT_UNASSIGNED => 'person-dash',
            self::EVENT_COMMENTED => 'chat-dots',
            self::EVENT_COMMENT_DELETED => 'trash',
            default => match ($this->field) {
                'status' => 'arrow-repeat',
                'priority' => 'flag',
                'due_date' => 'calendar-event',
                default => 'pencil',
            },
        };
    }
}
