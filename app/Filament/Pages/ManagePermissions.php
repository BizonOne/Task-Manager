<?php

namespace App\Filament\Pages;

use App\Models\RolePermission;
use App\Support\PermissionDefaults;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * The permission matrix, per role.
 *
 * Three things used to be confused with each other here: the admin panel's own
 * permissions, the app's system abilities, and who may touch which records.
 * This page separates them and says so on the screen.
 */
class ManagePermissions extends Page
{
    protected string $view = 'filament.pages.manage-permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Permissions';

    /** The role being edited. */
    public ?int $roleId = null;

    /**
     * Checkbox state, keyed by a Livewire-safe form of the permission key.
     *
     * Livewire reads a dot in wire:model as a nested array path, so
     * "task.edit.all" would bind to $granted['task']['edit']['all'] instead of
     * the flat key. The dots are swapped out here and back again on save.
     *
     * @var array<string, bool>
     */
    public array $granted = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->roleId = $this->editableRoles()->first()?->id;
        $this->loadRole();
    }

    /**
     * Roles whose permissions are worth editing.
     *
     * super_admin is left out: it bypasses every check by design, so a screen
     * of ticked boxes for it would be a lie you could untick.
     */
    public function editableRoles()
    {
        return Role::whereNot('name', 'super_admin')->orderBy('name')->get();
    }

    public function updatedRoleId(): void
    {
        $this->loadRole();
    }

    public function loadRole(): void
    {
        $held = $this->roleId === null
            ? []
            : RolePermission::where('role_id', $this->roleId)->pluck('permission')->all();

        $this->granted = [];
        foreach (Permissions::keys() as $key) {
            $this->granted[self::field($key)] = in_array($key, $held, true);
        }
    }

    /** The Livewire-safe name for a permission key. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save permissions')->action('save'),

            Action::make('reset')
                ->label('Reset to defaults')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Puts this role back to the permissions it shipped with. Any changes made here are lost.')
                ->action('resetToDefaults'),
        ];
    }

    public function save(): void
    {
        if ($this->roleId === null) {
            return;
        }

        $wanted = collect($this->granted)
            ->filter()
            ->keys()
            ->map(fn (string $field): string => str_replace('__', '.', $field))
            // Never store a key the app does not recognise — a stale checkbox
            // would sit in the table forever granting nothing.
            ->intersect(Permissions::keys())
            ->values();

        RolePermission::where('role_id', $this->roleId)
            ->whereNotIn('permission', $wanted)
            ->delete();

        foreach ($wanted as $key) {
            RolePermission::firstOrCreate(['role_id' => $this->roleId, 'permission' => $key]);
        }

        Permissions::markConfigured();
        $this->loadRole();

        Notification::make()
            ->title('Permissions saved')
            ->body('They apply on the next page a person loads.')
            ->success()
            ->send();
    }

    public function resetToDefaults(): void
    {
        $role = Role::find($this->roleId);

        if ($role === null) {
            return;
        }

        RolePermission::where('role_id', $role->id)->delete();

        foreach (PermissionDefaults::all()[$role->name] ?? [] as $key) {
            RolePermission::create(['role_id' => $role->id, 'permission' => $key]);
        }

        Permissions::forget();
        $this->loadRole();

        Notification::make()->title('Back to the defaults for '.$role->name)->success()->send();
    }

    /**
     * Tick or untick a whole row at once.
     */
    public function toggleEntity(string $entity, bool $on): void
    {
        foreach (array_keys($this->granted) as $field) {
            if (str_starts_with($field, $entity.'__')) {
                $this->granted[$field] = $on;
            }
        }
    }
}
