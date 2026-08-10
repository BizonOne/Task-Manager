<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Where this person has asked to be told about their work.
     */
    public function notificationChannels()
    {
        return $this->hasMany(NotificationChannel::class);
    }

    /**
     * Roles that are allowed to sign in to the Filament admin panel.
     */
    public const STAFF_ROLES = ['super_admin', 'admin'];

    /**
     * Gate access to the Filament admin panel: only staff roles get in,
     * everyone else stays in the regular front-end app.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }

    /**
     * A super admin oversees the whole workspace: they can view and act on
     * every project and task in the front-end, not just the ones they own or
     * are a member of. This mirrors the Gate::before bypass used in the admin
     * panel so support/ownership can always step into any project.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Whether this person oversees other people's work rather than only their
     * own. Decides how wide their reports and their archive reach.
     */
    public function oversees(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'invited_at' => 'datetime',
        'invitation_accepted_at' => 'datetime',
        'last_active_at' => 'datetime',
    ];

    /**
     * Whether this user was invited and has not accepted yet — they still have
     * no password and cannot sign in.
     */
    public function isPendingInvitation(): bool
    {
        return $this->invitation_token !== null && $this->invitation_accepted_at === null;
    }

    /**
     * Lifecycle status shown in the admin panel.
     */
    public function getAccountStatusAttribute(): string
    {
        if ($this->isPendingInvitation()) {
            return 'invited';
        }

        return $this->password === null ? 'no_password' : 'active';
    }

    /**
     * Who invited this user, if they came in through an invitation.
     */
    public function invitedBy()
    {
        return $this->belongsTo(self::class, 'invited_by_id');
    }

    /**
     * Get the projects for the user.
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the tasks for the user.
     */
    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the routines for the user.
     */
    public function routines()
    {
        return $this->hasMany(Routine::class);
    }

    /**
     * Get the notes for the user.
     */
    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the calendar events for the user.
     */
    public function files()
    {
        return $this->hasMany(File::class);
    }

    public function reminders()
    {
        return $this->hasMany(Reminder::class);
    }

    public function projectMembers()
    {
        return $this->belongsToMany(Project::class, 'project_teams', 'user_id', 'project_id');
    }

    /**
     * Tasks this user has been assigned to collaborate on.
     */
    public function assignedTasks()
    {
        return $this->belongsToMany(Task::class, 'task_user')->withTimestamps();
    }
}
