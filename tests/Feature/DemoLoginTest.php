<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_credentials_from_the_readme_can_log_in(): void
    {
        $this->seed(DemoUserSeeder::class);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        // Assert the destination actually resolves — the previous version
        // asserted the literal path 'dashboard', which 404s.
        $response->assertRedirect(route('dashboard'));
        $this->followRedirects($response)->assertSuccessful();
        $this->assertAuthenticatedAs(User::where('email', 'admin@example.com')->first());
    }

    public function test_ticking_remember_me_issues_a_remember_token(): void
    {
        $this->seed(DemoUserSeeder::class);

        // The box promised thirty days and delivered nothing: the form sent
        // `remember` and Auth::attempt() was never handed it. The token is
        // the proof the promise is now kept — without it, the recaller
        // cookie cannot exist.
        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
            'remember' => '1',
        ]);

        $this->assertNotNull(User::where('email', 'admin@example.com')->first()->remember_token);
    }

    public function test_leaving_remember_me_unticked_issues_no_token(): void
    {
        $this->seed(DemoUserSeeder::class);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->assertNull(User::where('email', 'admin@example.com')->first()->remember_token);
    }

    public function test_login_is_rejected_with_the_wrong_password(): void
    {
        $this->seed(DemoUserSeeder::class);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_demo_user_seeder_is_idempotent_and_resets_the_password(): void
    {
        $this->seed(DemoUserSeeder::class);

        User::where('email', 'admin@example.com')->update(['password' => bcrypt('stale')]);

        $this->seed(DemoUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $this->assertTrue(auth()->validate([
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]));
    }

    public function test_full_database_seeder_runs_and_creates_the_demo_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->tasks()->exists());
        $this->assertTrue($user->projects()->exists());
    }

    /**
     * Seeders run in production, where fakerphp/faker is not installed
     * (composer install --no-dev), so they must never touch model factories.
     */
    public function test_seeders_do_not_depend_on_model_factories(): void
    {
        foreach (glob(database_path('seeders/*.php')) as $seeder) {
            $contents = file_get_contents($seeder);

            $this->assertStringNotContainsString('::factory(', $contents, basename($seeder).' uses a model factory, which is unavailable in production.');
            $this->assertStringNotContainsString('fake(', $contents, basename($seeder).' uses fake(), which is unavailable in production.');
        }
    }
}
