<?php

namespace Tests\Feature;

use App\Filament\Pages\ManagePermissions;
use App\Models\Note;
use App\Models\Project;
use App\Models\RolePermission;
use App\Models\Task;
use App\Models\User;
use App\Support\PermissionDefaults;
use App\Support\Permissions;
use Database\Seeders\PermissionMatrixSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who may do what, per role.
 *
 * The defaults exist to reproduce exactly what the app did before there was a
 * matrix, so most of this file is a guard against the new layer quietly
 * changing somebody's access on the day it ships.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $colleague;

    private User $admin;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionMatrixSeeder::class);

        $this->member = $this->userWith('member', 'Mel Member', 'mel@example.com');
        $this->colleague = $this->userWith('member', 'Cory Colleague', 'cory@example.com');
        $this->admin = $this->userWith('admin', 'Ada Admin', 'ada@example.com');

        $this->project = Project::create(['user_id' => $this->colleague->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach($this->member->id);

        $this->task = Task::create([
            'user_id' => $this->colleague->id,
            'project_id' => $this->project->id,
            'title' => 'Corys work',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    private function userWith(string $role, string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('secret')]);
        $user->assignRole($role);

        return $user;
    }

    private function grant(string $role, string $key): void
    {
        RolePermission::firstOrCreate([
            'role_id' => Role::where('name', $role)->value('id'),
            'permission' => $key,
        ]);
        Permissions::forget();
    }

    private function revoke(string $role, string $key): void
    {
        RolePermission::where('role_id', Role::where('name', $role)->value('id'))
            ->where('permission', $key)->delete();
        Permissions::forget();
    }

    // --- the defaults reproduce what the app already did --------------------

    public function test_a_member_can_see_a_colleagues_task_in_a_shared_project(): void
    {
        // A shared board is the point of a project.
        $this->assertTrue($this->task->isAccessibleBy($this->member));
    }

    public function test_a_member_cannot_edit_a_colleagues_task(): void
    {
        $this->assertFalse($this->task->isManageableBy($this->member));
    }

    public function test_a_member_can_edit_a_task_they_raised(): void
    {
        // Signed in as Mel, because authorship is recorded from the actor and
        // deliberately cannot be passed in as an attribute.
        $this->actingAs($this->member);

        $mine = Task::create([
            'user_id' => $this->colleague->id,
            'project_id' => $this->project->id,
            'title' => 'Raised by Mel',
            'priority' => 'low',
            'status' => 'to_do',
        ]);

        $this->assertSame($this->member->id, $mine->created_by);
        $this->assertTrue($mine->fresh()->isManageableBy($this->member));
    }

    public function test_someone_outside_the_project_sees_nothing_of_it(): void
    {
        $outsider = $this->userWith('member', 'Otto Outside', 'otto@example.com');

        $this->assertFalse($this->task->isAccessibleBy($outsider));
        $this->assertFalse($this->project->isAccessibleBy($outsider));
    }

    public function test_an_admin_reaches_across_projects(): void
    {
        $this->assertTrue($this->task->isAccessibleBy($this->admin));
        $this->assertTrue($this->task->isManageableBy($this->admin));
    }

    public function test_an_admin_does_not_get_to_read_personal_notes(): void
    {
        $note = Note::create(['user_id' => $this->member->id, 'title' => 'Private', 'content' => 'x']);

        // Overseeing the work is not a reason to read somebody's to-do list.
        $this->assertFalse(Permissions::allows($this->admin, 'view', $note));
        $this->assertTrue(Permissions::allows($this->member, 'view', $note));
    }

    public function test_a_super_admin_bypasses_the_matrix_entirely(): void
    {
        $boss = $this->userWith('super_admin', 'Sue Super', 'sue@example.com');

        $this->assertTrue($this->task->isManageableBy($boss));
        $this->assertTrue(Permissions::granted($boss, 'anything.at.all'));
    }

    // --- the matrix actually governs ----------------------------------------

    public function test_granting_edit_all_lets_a_member_edit_a_colleagues_task(): void
    {
        $this->assertFalse($this->task->isManageableBy($this->member));

        $this->grant('member', 'task.edit.all');

        $this->assertTrue($this->task->fresh()->isManageableBy($this->member));
    }

    public function test_revoking_team_view_hides_a_colleagues_task(): void
    {
        $this->assertTrue($this->task->isAccessibleBy($this->member));

        $this->revoke('member', 'task.view.team');

        $this->assertFalse($this->task->fresh()->isAccessibleBy($this->member));
    }

    public function test_a_revoked_permission_takes_effect_on_the_next_request(): void
    {
        $this->actingAs($this->member)->get("/tasks/{$this->task->id}")->assertSuccessful();

        $this->revoke('member', 'task.view.team');

        $this->actingAs($this->member)->get("/tasks/{$this->task->id}")->assertForbidden();
    }

    public function test_create_is_a_single_flag_with_no_scope(): void
    {
        $this->assertTrue(Permissions::canCreate($this->member, 'task'));

        $this->revoke('member', 'task.create');

        $this->assertFalse(Permissions::canCreate($this->member, 'task'));
    }

    public function test_a_user_with_no_role_is_treated_as_a_member(): void
    {
        $stray = User::create(['name' => 'Nora None', 'email' => 'nora@example.com', 'password' => bcrypt('secret')]);

        // Not more powerful than someone who does hold the default role.
        $this->assertFalse(Permissions::allows($stray, 'edit', $this->task));
        $this->assertTrue(Permissions::canCreate($stray, 'task'));
    }

    public function test_a_project_manager_keeps_their_say_whatever_the_matrix_says(): void
    {
        $this->project->users()->updateExistingPivot($this->member->id, ['role' => 'manager']);
        $this->revoke('member', 'task.edit.own');
        $this->revoke('member', 'task.edit.team');

        // Managing the project is what the role is for.
        $this->assertTrue($this->task->fresh()->isManageableBy($this->member));
    }

    // --- the seeder ---------------------------------------------------------

    public function test_seeding_twice_does_not_undo_a_deliberate_change(): void
    {
        $this->revoke('member', 'task.view.team');

        $this->seed(PermissionMatrixSeeder::class);

        // A deploy must not quietly hand back a permission somebody removed.
        $this->assertFalse(Permissions::granted($this->member, 'task.view.team'));
    }

    // --- the settings screen ------------------------------------------------

    public function test_the_settings_screen_saves_a_change_and_leaves_the_rest_alone(): void
    {
        $boss = $this->userWith('super_admin', 'Sue Super', 'sue@example.com');
        $memberRole = Role::where('name', 'member')->first();

        // Livewire reads a dot in wire:model as a nested array path, so the
        // checkboxes are named with the dots swapped out. Bind the wrong name
        // and every box silently stops working — hence this test.
        $field = ManagePermissions::field('task.edit.all');

        Livewire::actingAs($boss)
            ->test(ManagePermissions::class)
            ->set('roleId', $memberRole->id)
            ->call('loadRole')
            ->assertSet("granted.{$field}", false)
            ->set("granted.{$field}", true)
            ->call('save');

        Permissions::forget();

        $this->assertTrue(Permissions::granted($this->member, 'task.edit.all'));
        // Saving one box must not quietly drop the others.
        $this->assertTrue(Permissions::granted($this->member, 'task.view.team'));
    }

    public function test_the_settings_screen_can_take_a_permission_away(): void
    {
        $boss = $this->userWith('super_admin', 'Sue Super', 'sue@example.com');
        $memberRole = Role::where('name', 'member')->first();
        $field = ManagePermissions::field('task.view.team');

        Livewire::actingAs($boss)
            ->test(ManagePermissions::class)
            ->set('roleId', $memberRole->id)
            ->call('loadRole')
            ->set("granted.{$field}", false)
            ->call('save');

        Permissions::forget();

        $this->assertFalse(Permissions::granted($this->member, 'task.view.team'));
    }

    public function test_only_a_super_admin_may_open_the_settings_screen(): void
    {
        $this->assertFalse(ManagePermissions::canAccess());

        $this->actingAs($this->admin);
        $this->assertFalse(ManagePermissions::canAccess());

        $this->actingAs($this->userWith('super_admin', 'Sue Super', 'sue@example.com'));
        $this->assertTrue(ManagePermissions::canAccess());
    }

    public function test_every_default_is_a_key_the_app_recognises(): void
    {
        $keys = Permissions::keys();

        foreach (PermissionDefaults::all() as $role => $granted) {
            foreach ($granted as $key) {
                $this->assertContains($key, $keys, "{$role} is granted an unknown key: {$key}");
            }
        }
    }
}
