<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use App\Support\PermissionDefaults;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Installs the starting permissions for each role.
 *
 * Idempotent, and it only ever *adds*: once somebody has edited the matrix, a
 * later deploy must not quietly hand a role back a permission it was taken
 * off. New keys added in a future release still reach existing roles.
 */
class PermissionMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $installed = 0;

        foreach (PermissionDefaults::all() as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            // Only seed a role that has never been configured. Re-running must
            // not undo somebody's deliberate change.
            if (RolePermission::where('role_id', $role->id)->exists()) {
                continue;
            }

            foreach ($keys as $key) {
                RolePermission::firstOrCreate(['role_id' => $role->id, 'permission' => $key]);
                $installed++;
            }
        }

        Permissions::markConfigured();

        $this->command?->info($installed === 0
            ? 'Permission matrix already configured; left alone.'
            : "Permission matrix installed ({$installed} grants).");
    }
}
