<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskActivity;

/**
 * Writes a task's history. Living on the model's events means every edit is
 * captured — the front-end forms, the kanban drag-and-drop and the admin panel
 * alike — instead of each controller having to remember to log.
 */
class TaskObserver
{
    /**
     * Fields worth tracking. Anything else (timestamps, slugs) is noise.
     */
    private const TRACKED = [
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'estimated_hours',
        'project_id',
        'user_id',
    ];

    public function created(Task $task): void
    {
        TaskActivity::record($task, TaskActivity::EVENT_CREATED);
    }

    public function updated(Task $task): void
    {
        foreach (self::TRACKED as $field) {
            if (! $task->wasChanged($field)) {
                continue;
            }

            $old = $task->getOriginal($field);
            $new = $task->getAttribute($field);

            // Dates come back as Carbon; store a stable string either way.
            TaskActivity::record($task, TaskActivity::EVENT_UPDATED, [
                'field' => $field,
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
            ]);
        }
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
