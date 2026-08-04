<?php

namespace App\Models;

use App\Support\Permissions;
use App\Support\RichText;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'start_date',
        'end_date',
        'status',
    ];

    protected $dates = [
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Project descriptions are rich text, stored as HTML and rendered unescaped.
     *
     * Living on the model means every write is covered — the web forms, the
     * admin panel and the assistant's tools alike.
     */
    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = RichText::clean($value);
    }

    /**
     * Use slug for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Auto-generate unique slug on creating/updating.
     */
    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->slug = static::uniqueSlug($project->name, $project->id);
        });

        static::updating(function (Project $project) {
            if ($project->isDirty('name')) {
                $project->slug = static::uniqueSlug($project->name, $project->id);
            }
        });

        // The project_teams pivot has no ON DELETE CASCADE on project_id, so
        // detach members before deleting to avoid a foreign-key violation.
        static::deleting(function (Project $project) {
            $project->users()->detach();
        });
    }

    private static function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The board columns this project keeps for itself. Empty means it uses
     * the shared ones.
     */
    public function statuses()
    {
        return $this->hasMany(TaskStatus::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The fields this project records on its tasks, in the order it set.
     */
    public function fields()
    {
        return $this->hasMany(ProjectField::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Files attached to this project's tasks. The files table has no
     * project_id — this used to point at one that never existed.
     */
    public function files()
    {
        return $this->hasManyThrough(File::class, Task::class);
    }

    public function getDerivedStatusAttribute()
    {
        $today = Carbon::now();

        if ($this->start_date && $today->lt($this->start_date)) {
            return 'pending';
        }

        if ($this->end_date && $this->end_date->lt($today)) {
            $unfinishedTasks = $this->tasks()->notCompleted()->count();

            return $unfinishedTasks > 0 ? 'unfinished' : 'finished';
        }

        return 'on_going';
    }

    public function teamProjects()
    {
        return $this->belongsToMany(ProjectTeam::class, 'project_teams', 'project_id', 'user_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_teams', 'project_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Whether the given user may view and work with this project: its owner
     * or any of its team members.
     */
    public function isAccessibleBy(User $user): bool
    {
        return Permissions::allows($user, 'view', $this);
    }

    /**
     * Raw membership: the owner, or someone on the team.
     *
     * Deliberately free of any permission check — the permission layer asks
     * this question to answer "team", so it cannot be the answer as well.
     */
    public function hasMember(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Whether the given user may manage this project (edit details, add/remove
     * members): the owner or a member with the "manager" project role.
     */
    public function isManagedBy(User $user): bool
    {
        if ($user->isSuperAdmin() || $this->user_id === $user->id) {
            return true;
        }

        return $this->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();
    }
}
