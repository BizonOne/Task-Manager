<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates (or repairs) the demo account documented in the README.
 *
 * Deliberately avoids model factories: they rely on the faker helper, which
 * ships with fakerphp/faker — a require-dev package that is absent from
 * production installs (composer install --no-dev). Seeding a deployed
 * environment must therefore stay factory-free.
 */
class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = env('DEMO_USER_EMAIL', 'admin@example.com');
        $password = env('DEMO_USER_PASSWORD', 'secret');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('DEMO_USER_NAME', 'Arafat Hossain'),
                'password' => Hash::make($password),
            ]
        );

        // email_verified_at is not mass assignable, so set it explicitly.
        if (is_null($user->email_verified_at)) {
            $user->email_verified_at = now();
            $user->save();
        }

        // Make the demo account a super admin so it can reach /admin. Guarded by
        // role existence so this seeder still works before RoleSeeder has run.
        if (Role::where('name', 'super_admin')->exists() && ! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }

        $this->command?->info("Demo user ready: {$email}");
    }
}
