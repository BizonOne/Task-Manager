<?php

namespace App\Models;

use App\Support\RichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'body',
    ];

    /**
     * Comments are rich text now, so the same filtering applies. A comment is
     * the most exposed field of the lot — anyone on a project can post one.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = RichText::clean($value);
    }

    /**
     * Plain-text rendering, for notification emails and previews.
     */
    public function getPlainBodyAttribute(): string
    {
        return RichText::toText($this->body);
    }

    /**
     * Files posted with this comment.
     */
    public function files()
    {
        return $this->hasMany(File::class, 'task_comment_id')->oldest();
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
