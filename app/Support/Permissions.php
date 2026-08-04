<?php

namespace App\Support;

use App\Models\File;
use App\Models\Note;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\RolePermission;
use App\Models\Routine;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Who may do what, per role.
 *
 * Three separate things used to be confused with each other, which is why the
 * admin panel's permission list looked like it governed the whole app:
 *
 *  1. Admin panel permissions — Shield's, and they govern the panel only.
 *  2. System permissions      — app-wide abilities (below).
 *  3. Content permissions     — the matrix: entity x action x how far it reaches.
 *
 * Only 2 and 3 live here. A permission is granted by the presence of a row, so
 * an ungranted role simply has no row — there is no "denied" state to keep in
 * step with anything.
 */
class Permissions
{
    private const CACHE_KEY = 'role_permissions.map';

    /**
     * Set once the matrix has been installed or edited.
     *
     * Until then the table is empty because nobody has configured anything —
     * not because every role was stripped of everything — and the app must run
     * on its defaults rather than lock everyone out. After that, empty means
     * empty: a role somebody deliberately cleared stays cleared.
     */
    public const CONFIGURED_FLAG = 'permissions.configured';

    /** Things a role may do that are not about one particular record. */
    public const SYSTEM = [
        'system.export-reports' => 'Export reports to Excel and PDF',
        'system.archive-tasks' => 'Move tasks to the archive and back',
        'system.link-tasks' => 'Link tasks to each other',
    ];

    /**
     * The content types, and how far each permission can reach for them.
     *
     * "Team" only means something where a project is involved. A note or a
     * reminder is personal — there is no team to widen to, so those types
     * offer own and all and nothing in between.
     *
     * @var array<string, array{label: string, scopes: array<int, string>}>
     */
    public const ENTITIES = [
        'project' => ['label' => 'Projects', 'scopes' => ['own', 'team', 'all']],
        'task' => ['label' => 'Tasks', 'scopes' => ['own', 'team', 'all']],
        'comment' => ['label' => 'Comments', 'scopes' => ['own', 'team', 'all']],
        'file' => ['label' => 'Files', 'scopes' => ['own', 'team', 'all']],
        'note' => ['label' => 'Notes', 'scopes' => ['own', 'all']],
        'reminder' => ['label' => 'Reminders', 'scopes' => ['own', 'all']],
        'routine' => ['label' => 'Routines', 'scopes' => ['own', 'all']],
    ];

    /** Create has no scope: you either may raise one or you may not. */
    public const ACTIONS = ['create', 'view', 'edit', 'delete'];

    public const SCOPE_LABELS = [
        'own' => 'Own',
        'team' => 'Team',
        'all' => 'All',
    ];

    /**
     * What each scope actually means, in the words of someone setting it.
     */
    public const SCOPE_HELP = [
        'own' => 'Records this person raised, owns, or was assigned',
        'team' => 'Anything in a project they belong to',
        'all' => 'Everything in the workspace',
    ];

    /**
     * Every valid permission key.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        $keys = array_keys(self::SYSTEM);

        foreach (self::ENTITIES as $entity => $meta) {
            $keys[] = $entity.'.create';

            foreach (['view', 'edit', 'delete'] as $action) {
                foreach ($meta['scopes'] as $scope) {
                    $keys[] = $entity.'.'.$action.'.'.$scope;
                }
            }
        }

        return $keys;
    }

    /**
     * role name => [permission key, ...]
     *
     * @return array<string, array<int, string>>
     */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                if (! self::isConfigured()) {
                    return PermissionDefaults::all();
                }

                return RolePermission::query()
                    ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                    ->get(['roles.name as role', 'role_permissions.permission'])
                    ->groupBy('role')
                    ->map(fn ($rows) => $rows->pluck('permission')->all())
                    ->all();
            } catch (\Throwable) {
                // Before the table exists (mid-deploy), fall back to defaults
                // rather than locking everyone out of the app.
                return PermissionDefaults::all();
            }
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Whether anybody has installed or edited the matrix yet.
     */
    public static function isConfigured(): bool
    {
        return Brand::get(self::CONFIGURED_FLAG) === '1';
    }

    /**
     * Called by the seeder and by the settings screen. From here on the table
     * is the truth, including when a role holds nothing at all.
     */
    public static function markConfigured(): void
    {
        Brand::set(self::CONFIGURED_FLAG, '1');
        self::forget();
    }

    /**
     * Whether the user's roles grant this exact key.
     */
    public static function granted(User $user, string $key): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $map = self::map();
        $roles = $user->getRoleNames();

        // Somebody with no role at all is treated as a member — that is the
        // default front-end role, and a user without one should not be more
        // powerful than one who has it.
        if ($roles->isEmpty()) {
            $roles = collect(['member']);
        }

        foreach ($roles as $role) {
            if (in_array($key, $map[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this person create records of this type?
     */
    public static function canCreate(User $user, string $entity): bool
    {
        return self::granted($user, $entity.'.create');
    }

    /**
     * May this person do `$action` to `$record`?
     *
     * Checked widest-first: an "all" grant answers immediately, "team" asks
     * whether the record sits in a project they belong to, and "own" asks
     * whether it is theirs.
     */
    public static function allows(User $user, string $action, Model $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $entity = self::entityFor($record);

        if ($entity === null) {
            return false;
        }

        if (self::granted($user, "{$entity}.{$action}.all")) {
            return true;
        }

        if (self::granted($user, "{$entity}.{$action}.team") && self::isInTeamOf($user, $record)) {
            return true;
        }

        return self::granted($user, "{$entity}.{$action}.own") && self::isOwnedBy($user, $record);
    }

    /**
     * The permission-matrix name for a model.
     */
    public static function entityFor(Model $record): ?string
    {
        return match (true) {
            $record instanceof Project => 'project',
            $record instanceof Task => 'task',
            $record instanceof TaskComment => 'comment',
            $record instanceof File => 'file',
            $record instanceof Note => 'note',
            $record instanceof Reminder => 'reminder',
            $record instanceof Routine => 'routine',
            default => null,
        };
    }

    /**
     * "Own" — the record is this person's.
     *
     * For a task that means either end of it: the person who raised it and the
     * person it landed on both regard it as theirs, and both are right.
     */
    public static function isOwnedBy(User $user, Model $record): bool
    {
        if ($record instanceof Task) {
            return $record->user_id === $user->id
                || $record->created_by === $user->id
                || $record->assignees->contains('id', $user->id);
        }

        if ($record instanceof File) {
            return $record->user_id === $user->id
                || ($record->task && self::isOwnedBy($user, $record->task));
        }

        return ($record->user_id ?? null) === $user->id;
    }

    /**
     * "Team" — the record belongs to a project this person is on.
     */
    public static function isInTeamOf(User $user, Model $record): bool
    {
        $project = match (true) {
            $record instanceof Project => $record,
            $record instanceof Task => $record->project,
            $record instanceof TaskComment => $record->task?->project,
            $record instanceof File => $record->task?->project,
            default => null,
        };

        // hasMember(), not isAccessibleBy(): the latter asks this class the
        // very question being answered here.
        return $project !== null && $project->hasMember($user);
    }
}
