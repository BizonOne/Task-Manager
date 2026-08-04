<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'task_id',
        'task_comment_id',
        'name',
        'original_name',
        'path',
        'type',
        'mime_type',
        'size',
    ];

    /**
     * Whether the browser can sensibly display this inline rather than
     * downloading it.
     */
    public function isViewableInline(): bool
    {
        return in_array($this->mime_type, [
            'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/svg+xml',
        ], true);
    }

    public function isImage(): bool
    {
        return Str::startsWith((string) $this->mime_type, 'image/');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function comment()
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    /**
     * Who may see and download this file.
     *
     * An attachment belongs to the conversation it was posted in, not only to
     * whoever dragged it in — otherwise nobody else on the task could open it.
     * A file uploaded from the Files page stays private to its owner.
     */
    public function isAccessibleBy(User $user): bool
    {
        return Permissions::allows($user, 'view', $this);
    }

    /**
     * Who may rename, replace or delete it: the person who uploaded it, and
     * whoever manages the task it hangs on.
     */
    public function isManageableBy(User $user): bool
    {
        // Whoever manages the task a file hangs on manages the file too.
        return Permissions::allows($user, 'edit', $this)
            || ($this->task !== null && $this->task->isManageableBy($user));
    }

    /**
     * Size in units a person reads, e.g. "1.4 MB".
     */
    public function getReadableSizeAttribute(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $size : round($size, 1)).' '.$units[$i];
    }

    /**
     * A Bootstrap icon that matches the kind of file.
     */
    public function getIconAttribute(): string
    {
        $mime = (string) $this->mime_type;
        $extension = Str::lower(pathinfo((string) ($this->original_name ?: $this->path), PATHINFO_EXTENSION));

        return match (true) {
            Str::startsWith($mime, 'image/') => 'bi-file-earmark-image',
            $mime === 'application/pdf' => 'bi-file-earmark-pdf',
            in_array($extension, ['doc', 'docx'], true) => 'bi-file-earmark-word',
            in_array($extension, ['xls', 'xlsx', 'csv'], true) => 'bi-file-earmark-spreadsheet',
            in_array($extension, ['zip', 'rar', '7z'], true) => 'bi-file-earmark-zip',
            in_array($extension, ['js', 'css', 'html', 'php'], true) => 'bi-file-earmark-code',
            default => 'bi-file-earmark-text',
        };
    }

    /**
     * The `type` column is a coarse category chosen in the Files form. An
     * attachment has no form to choose it in, so derive it.
     */
    public static function categoryFor(?string $mimeType, string $filename): string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            Str::startsWith((string) $mimeType, 'image/') => 'image',
            in_array($extension, ['js', 'css', 'html', 'php', 'json'], true) => 'code',
            in_array($extension, ['txt', 'md', 'csv'], true) => 'txt',
            default => 'docs',
        };
    }
}
