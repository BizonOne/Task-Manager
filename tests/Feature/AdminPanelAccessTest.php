<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(?string $role): User
    {
        $user = User::create([
            'name' => 'Test '.($role ?? 'none'),
            'email' => ($role ?? 'none').'@example.com',
            'password' => bcrypt('secret'),
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_guests_are_redirected_away_from_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_super_admin_can_reach_the_admin_panel(): void
    {
        $response = $this->actingAs($this->userWithRole('super_admin'))->get('/admin');

        $response->assertSuccessful();
    }

    public function test_admin_can_reach_the_admin_panel(): void
    {
        $response = $this->actingAs($this->userWithRole('admin'))->get('/admin');

        $response->assertSuccessful();
    }

    public function test_member_is_forbidden_from_the_admin_panel(): void
    {
        $this->actingAs($this->userWithRole('member'))->get('/admin')->assertForbidden();
    }

    public function test_user_without_a_role_is_forbidden_from_the_admin_panel(): void
    {
        $this->actingAs($this->userWithRole(null))->get('/admin')->assertForbidden();
    }

    public function test_admin_role_can_manage_content_but_not_roles(): void
    {
        $admin = $this->userWithRole('admin');

        $this->assertTrue($admin->can('ViewAny:Project'));
        $this->assertTrue($admin->can('Delete:Task'));
        $this->assertTrue($admin->can('Create:User'));
        $this->assertFalse($admin->can('ViewAny:Role'));
        $this->assertFalse($admin->can('Delete:Role'));
    }

    public function test_super_admin_bypasses_every_permission_check(): void
    {
        $superAdmin = $this->userWithRole('super_admin');

        $this->assertTrue($superAdmin->can('ViewAny:Role'));
        $this->assertTrue($superAdmin->can('ForceDeleteAny:User'));
        $this->assertTrue($superAdmin->can('some.permission.that.does.not.exist'));
    }
}
