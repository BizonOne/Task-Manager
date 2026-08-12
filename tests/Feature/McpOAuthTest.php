<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The OAuth path onto the MCP server: how claude.ai and Claude Code get in
 * without anyone copying a token by hand.
 *
 * A connector discovers the endpoints, registers itself, sends its person
 * here to click Authorize, and holds the grant it is given. What these
 * tests defend: discovery tells the truth, registration only accepts
 * clients that call back to places we trust, and a grant opens exactly
 * the same door a hand-issued token does.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Olive OAuth', 'email' => 'olive@example.com', 'password' => bcrypt('secret')]);
    }

    // --- discovery --------------------------------------------------------------

    public function test_a_connector_can_discover_the_authorization_server(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertSuccessful()
            ->assertJson([
                'authorization_endpoint' => route('passport.authorizations.authorize'),
                'token_endpoint' => route('passport.token'),
            ])
            ->assertJsonPath('registration_endpoint', url('oauth/register'));
    }

    public function test_the_protected_resource_names_its_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertSuccessful()
            ->assertJsonPath('authorization_servers.0', url('/'));
    }

    // --- registration -------------------------------------------------------------

    public function test_a_client_calling_back_to_claude_registers_itself(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $response->assertCreated()->assertJsonStructure(['client_id']);

        $this->assertDatabaseHas('oauth_clients', ['name' => 'Claude']);
    }

    public function test_a_client_calling_back_somewhere_strange_is_refused(): void
    {
        // The redirect URI is where authorization codes are delivered. An
        // attacker who can register evil.example as a callback can collect
        // codes meant for real clients — the allowlist is the defence.
        // 400 rather than 422: RFC 7591 prescribes invalid_client_metadata.
        $this->postJson('/oauth/register', [
            'client_name' => 'Evil',
            'redirect_uris' => ['https://evil.example/callback'],
        ])->assertBadRequest();

        $this->assertDatabaseMissing('oauth_clients', ['name' => 'Evil']);
    }

    // --- authorization ------------------------------------------------------------

    public function test_a_guest_sent_to_authorize_lands_on_the_login_page(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'st',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_a_signed_in_person_is_shown_who_asks_and_as_whom(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        $this->actingAs($this->user)
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'response_type' => 'code',
                'scope' => 'mcp:use',
                'state' => 'st',
                'code_challenge' => str_repeat('a', 43),
                'code_challenge_method' => 'S256',
            ]))
            ->assertSuccessful()
            ->assertSee('Claude wants to work as you')
            ->assertSee($this->user->email);
    }

    // --- the grant opens the same door -------------------------------------------

    public function test_an_oauth_grant_reaches_the_mcp_endpoint(): void
    {
        $project = Project::create(['user_id' => $this->user->id, 'name' => 'Owned', 'status' => 'in_progress']);
        Task::create(['user_id' => $this->user->id, 'project_id' => $project->id,
            'title' => 'Visible through OAuth', 'priority' => 'low', 'status' => 'to_do']);

        Passport::actingAs($this->user, ['mcp:use']);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list-tasks', 'arguments' => []],
        ], ['Accept' => 'application/json, text/event-stream'])
            ->assertSuccessful()
            ->assertSee('Visible through OAuth', false);
    }

    public function test_the_grant_carries_its_persons_boundary_not_more(): void
    {
        $other = User::create(['name' => 'Someone Else', 'email' => 'else@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $other->id, 'name' => 'Foreign', 'status' => 'in_progress']);
        Task::create(['user_id' => $other->id, 'project_id' => $project->id,
            'title' => 'Not yours to read', 'priority' => 'low', 'status' => 'to_do']);

        Passport::actingAs($this->user, ['mcp:use']);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list-tasks', 'arguments' => []],
        ], ['Accept' => 'application/json, text/event-stream'])
            ->assertSuccessful()
            ->assertDontSee('Not yours to read');
    }

    // --- revocation ---------------------------------------------------------------

    public function test_the_agents_page_lists_a_connection_and_revokes_it_whole(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        // Two tokens, as a connector accumulates over weeks of refreshes.
        foreach (range(1, 2) as $i) {
            $client->tokens()->create([
                'id' => 'token-'.$i,
                'user_id' => $this->user->id,
                'scopes' => ['mcp:use'],
                'revoked' => false,
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->actingAs($this->user)
            ->get(route('profile.agents'))
            ->assertSuccessful()
            ->assertSee('Claude');

        $this->actingAs($this->user)
            ->delete(route('profile.agents.connections.destroy', $client->id))
            ->assertRedirect();

        $this->assertSame(0, $client->tokens()->where('revoked', false)->count());
    }

    public function test_nobody_revokes_anybody_elses_connection(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        $client->tokens()->create([
            'id' => 'token-mine',
            'user_id' => $this->user->id,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);

        $other = User::create(['name' => 'Someone Else', 'email' => 'else@example.com', 'password' => bcrypt('secret')]);

        // The other person has no grant from this client, so for them the
        // connection does not exist.
        $this->actingAs($other)
            ->delete(route('profile.agents.connections.destroy', $client->id))
            ->assertNotFound();

        $this->assertSame(1, $client->tokens()->where('revoked', false)->count());
    }
}
