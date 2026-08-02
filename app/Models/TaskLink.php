<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A relation between two tasks.
 *
 * Each row is directional: the task it was created from is `task_id`, the one
 * it points at is `linked_task_id`. The other side reads the same row and
 * renders the inverse wording, so "A blocks B" and "B is blocked by A" are one
 * fact rather than two rows that can disagree.
 */
class TaskLink extends Model
{
    /**
     * The relations on offer, with the wording for each direction.
     *
     * @var array<string, array{outward: string, inward: string, icon: string}>
     */
    public const TYPES = [
        'blocks' => [
            'outward' => 'blocks',
            'inward' => 'is blocked by',
            'icon' => 'bi-sign-stop',
        ],
        'duplicates' => [
            'outward' => 'duplicates',
            'inward' => 'is duplicated by',
            'icon' => 'bi-files',
        ],
        'causes' => [
            'outward' => 'causes',
            'inward' => 'is caused by',
            'icon' => 'bi-lightning',
        ],
        'relates_to' => [
            'outward' => 'relates to',
            'inward' => 'relates to',
            'icon' => 'bi-link-45deg',
        ],
    ];

    protected $fillable = [
        'task_id',
        'linked_task_id',
        'type',
        'created_by',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function linkedTask()
    {
        return $this->belongsTo(Task::class, 'linked_task_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * How this relation reads from the given task's side.
     */
    public function labelFor(Task|int $task): string
    {
        $id = $task instanceof Task ? $task->id : $task;
        $type = self::TYPES[$this->type] ?? self::TYPES['relates_to'];

        return $id === $this->task_id ? $type['outward'] : $type['inward'];
    }

    /**
     * The task on the other end, seen from the given one.
     */
    public function otherEnd(Task|int $task): ?Task
    {
        $id = $task instanceof Task ? $task->id : $task;

        return $id === $this->task_id ? $this->linkedTask : $this->task;
    }

    public function getIconAttribute(): string
    {
        return (self::TYPES[$this->type] ?? self::TYPES['relates_to'])['icon'];
    }

    /**
     * The options a person picks from, phrased from their side: "this task
     * ___ that one".
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::TYPES as $key => $type) {
            $options[$key] = $type['outward'];
            // The inverse is offered as its own choice, stored as the opposite
            // relation with the two tasks swapped.
            if ($type['inward'] !== $type['outward']) {
                $options[$key.'_inverse'] = $type['inward'];
            }
        }

        return $options;
    }

    /**
     * Turn a picked option back into (type, whether the tasks swap places).
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function resolveOption(string $option): ?array
    {
        if (str_ends_with($option, '_inverse')) {
            $type = substr($option, 0, -8);

            return isset(self::TYPES[$type]) ? [$type, true] : null;
        }

        return isset(self::TYPES[$option]) ? [$option, false] : null;
    }
}
