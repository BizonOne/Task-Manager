<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the application's roles and Filament-panel permissions.
 *
 * This is the single source of truth for panel permissions: it creates every
 * "{Action}:{Entity}" permission Shield expects, so deploys never need to run
 * `shield:generate` (which would write policy files to a read-only app
 * filesystem). The generated policy files are committed to the repo; this
 * seeder only manages the matching database rows.
 *
 * Idempotent and factory-free — safe to run on every production deploy.
 * When a new Filament resource is added, extend self::ENTITIES and re-run
 * `php artisan shield:generate` locally to keep the committed policies in sync.
 */
class RoleSeeder extends Seeder
{
    /**
     * Shield's default permission actions per entity.
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'Replicate', 'Reorder', 'ForceDelete', 'ForceDeleteAny',
    ];

    /**
     * Entities exposed in the admin panel.
     */
    private const ENTITIES = ['Project', 'Task', 'User', 'Role'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = [];
        foreach (self::ENTITIES as $entity) {
            foreach (self::ACTIONS as $action) {
                $name = "{$action}:{$entity}";
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $allPermissions[] = $name;
            }
        }

        // super_admin bypasses every check via Shield's gate, so it needs no
        // explicit permissions. member is the default front-end role with no
        // panel access at all (enforced by User::canAccessPanel()).
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);

        // admin manages content but not roles/permissions themselves.
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminPermissions = array_values(array_filter(
            $allPermissions,
            fn (string $name): bool => ! str_ends_with($name, ':Role'),
        ));
        $admin->syncPermissions($adminPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Roles ready: super_admin, admin (%d permissions), member',
            count($adminPermissions),
        ));
    }
}
