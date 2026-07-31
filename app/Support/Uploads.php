<?php

namespace App\Support;

use App\Models\File;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One set of rules for every upload in the app.
 *
 * Files can now arrive from three places — the Files page, a task's attachment
 * list and the discussion — and they must agree on where uploads live, how big
 * they may be and what kinds are allowed. Keeping that in one place is the
 * difference between "files work" and "files work over there".
 */
class Uploads
{
    public const MAX_KILOBYTES = 20480;

    public const ALLOWED_MIMES = 'jpeg,png,jpg,gif,svg,doc,docx,xls,xlsx,pdf,txt,csv,zip,html,css,js';

    /**
     * Uploads made before files moved off the public disk still sit there;
     * reads fall back to it so old rows keep working.
     */
    private const LEGACY_DISK = 'public';

    /**
     * Where uploads live. Configurable so production can point at a bucket
     * without a code change — on Laravel Cloud the attached bucket arrives as
     * the "public" disk, so this is the only knob that needs to move.
     */
    public static function disk(): string
    {
        return config('filesystems.uploads', 'local');
    }

    /**
     * The validation rule for a single uploaded file.
     */
    public static function rule(bool $required = true): string
    {
        return ($required ? 'required' : 'nullable')
            .'|file|max:'.self::MAX_KILOBYTES.'|mimes:'.self::ALLOWED_MIMES;
    }

    /**
     * Store an upload and record it, optionally hung on a task or a comment.
     */
    public static function store(
        UploadedFile $upload,
        User $owner,
        ?Task $task = null,
        ?TaskComment $comment = null,
        ?string $name = null,
        ?string $type = null,
    ): File {
        $originalName = $upload->getClientOriginalName();
        $mimeType = $upload->getClientMimeType();

        return File::create([
            'user_id' => $owner->id,
            'task_id' => $task?->id ?? $comment?->task_id,
            'task_comment_id' => $comment?->id,
            'name' => $name ?: pathinfo($originalName, PATHINFO_FILENAME),
            'original_name' => $originalName,
            'path' => $upload->store('uploads', self::disk()),
            'type' => $type ?: File::categoryFor($mimeType, $originalName),
            'mime_type' => $mimeType,
            'size' => $upload->getSize(),
        ]);
    }

    /**
     * Which disk actually holds this file, or null if it is gone. The row can
     * outlive the file — a container filesystem is ephemeral.
     */
    public static function diskHolding(File $file): ?string
    {
        foreach (array_unique([self::disk(), self::LEGACY_DISK]) as $disk) {
            if ($file->path && Storage::disk($disk)->exists($file->path)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * Remove the stored file, leaving the row to the caller.
     */
    public static function deleteStored(File $file): void
    {
        $disk = self::diskHolding($file);

        if ($disk !== null) {
            Storage::disk($disk)->delete($file->path);
        }
    }
}
