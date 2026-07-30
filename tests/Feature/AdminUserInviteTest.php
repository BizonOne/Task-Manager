<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding a user through the admin panel invites them by email; they set their
 * own password through the emailed link.
 */
class AdminUserInviteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => bcrypt('secret')]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_creating_a_user_with_the_invite_toggle_sends_an_invitation_and_leaves_no_password(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Invited Person',
                'email' => 'invited@example.com',
                'send_invitation' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'invited@example.com')->firstOrFail();
        $this->assertNull($user->password, 'An invited user must not have a password yet.');
        $this->assertNotNull($user->invitation_token);
        $this->assertSame($admin->id, $user->invited_by_id);
        $this->assertTrue($user->isPendingInvitation());

        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    public function test_creating_a_user_with_the_invite_toggle_off_sets_the_password_and_sends_nothing(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Manual Person',
                'email' => 'manual@example.com',
                'send_invitation' => false,
                'password' => 'set-by-the-admin',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'manual@example.com')->firstOrFail();
        $this->assertNotNull($user->password);
        $this->assertNull($user->invitation_token);
        $this->assertSame('active', $user->account_status);

        Notification::assertNothingSent();
    }

    public function test_the_users_list_page_renders_the_lifecycle_columns(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin/users')
            ->assertSuccessful()
            ->assertSee('Last active')
            ->assertSee('Registered')
            ->assertSee('Invite accepted');
    }
}
