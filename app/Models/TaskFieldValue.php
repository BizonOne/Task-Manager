<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One task's answer to one of its project's fields.
 */
class TaskFieldValue extends Model
{
    protected $fillable = [
        'task_id',
        'project_field_id',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function field()
    {
        return $this->belongsTo(ProjectField::class, 'project_field_id');
    }

    /**
     * @return array<int, string>
     */
    public function list(): array
    {
        return array_values(array_filter((array) $this->value, fn ($v) => (string) $v !== ''));
    }

    /**
     * The answer as one line, for a card, a table cell or an export.
     */
    public function getDisplayAttribute(): string
    {
        return implode(', ', $this->list());
    }
}
