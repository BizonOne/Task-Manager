<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\Brand;
use App\Support\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invitee(): User
    {
        // An invited user exists with no password until they accept.
        return User::create(['name' => 'New Hire', 'email' => 'hire@example.com', 'password' => null]);
    }

    public function test_sending_an_invitation_stores_a_token_and_emails_the_invitee(): void
    {
        Notification::fake();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]);
        $user = $this->invitee();

        $token = Invitations::send($user, $admin);

        $user->refresh();
        $this->assertSame($token, $user->invitation_token);
        $this->assertNotNull($user->invited_at);
        $this->assertSame($admin->id, $user->invited_by_id);
        $this->assertNull($user->invitation_accepted_at);
        $this->assertTrue($user->isPendingInvitation());
        $this->assertSame('invited', $user->account_status);

        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    public function test_invitation_mail_is_addressed_and_links_to_the_accept_page(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]);
        $user = $this->invitee();

        $mail = (new UserInvitationNotification('tok-123', $admin))->toMail($user);

        $this->assertStringContainsString('invited', strtolower($mail->subject));
        $this->assertStringContainsString($admin->name, $mail->subject);

        // The invite link is the whole point of this email, so assert it is
        // actually in the rendered body (as a button and as pasteable text).
        $html = (string) $mail->render();
        $this->assertStringContainsString(route('invitation.show', 'tok-123'), $html);
        $this->assertStringContainsString($user->name, $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString(Brand::name(), $html);

        // Mail only — an invited user has no account to see a bell notification in.
        $this->assertSame(['mail'], (new UserInvitationNotification('tok-123'))->via($user));
    }

    public function test_the_invitation_page_loads_for_a_valid_token(): void
    {
        Notification::fake();
        $user = $this->invitee();
        $token = Invitations::send($user);

        $this->get(route('invitation.show', $token))
            ->assertSuccessful()
            ->assertSee('hire@example.com');
    }

    public function test_an_unknown_or_used_token_is_a_404(): void
    {
        Notification::fake();
        $this->get(route('invitation.show', 'not-a-real-token'))->assertNotFound();

        $user = $this->invitee();
        $token = Invitations::send($user);
        Invitations::accept($user, 'chosen-password');

        // The token is cleared on acceptance, so the link cannot be reused.
        $this->get(route('invitation.show', $token))->assertNotFound();
    }

    public function test_invitee_sets_their_own_password_and_is_signed_in(): void
    {
        Notification::fake();
        $user = $this->invitee();
        $token = Invitations::send($user);

        $this->post(route('invitation.accept', $token), [
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->password);
        $this->assertNull($user->invitation_token);
        $this->assertNotNull($user->invitation_accepted_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('active', $user->account_status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_acceptance_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        Notification::fake();
        $user = $this->invitee();
        $token = Invitations::send($user);

        $this->post(route('invitation.accept', $token), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->post(route('invitation.accept', $token), [
            'password' => 'long-enough-password',
            'password_confirmation' => 'does-not-match',
        ])->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->invitation_accepted_at);
        $this->assertGuest();
    }

    public function test_a_pending_invitee_cannot_sign_in_and_is_told_why(): void
    {
        Notification::fake();
        $user = $this->invitee();
        Invitations::send($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'anything-at-all'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'invitation is still pending',
            session('errors')->first('email')
        );
    }

    public function test_resending_an_invitation_invalidates_the_previous_link(): void
    {
        Notification::fake();
        $user = $this->invitee();

        $first = Invitations::send($user);
        $second = Invitations::send($user);

        $this->assertNotSame($first, $second);
        $this->get(route('invitation.show', $first))->assertNotFound();
        $this->get(route('invitation.show', $second))->assertSuccessful();
    }

    public function test_last_active_at_is_recorded_for_signed_in_users(): void
    {
        $user = User::create(['name' => 'Active', 'email' => 'active@example.com', 'password' => bcrypt('secret')]);
        $this->assertNull($user->last_active_at);

        $this->actingAs($user)->get('/projects')->assertSuccessful();

        $this->assertNotNull($user->fresh()->last_active_at);
    }
}
