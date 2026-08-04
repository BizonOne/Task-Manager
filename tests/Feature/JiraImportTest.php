<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Jira\Importer;
use App\Support\Jira\JiraClient;
use App\Support\Jira\Report;
use App\Support\Jira\StatusMap;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An import is a one-way door: it lands in the same database everyone is
 * working in, and there is no undo. So it has to be honest about what it will
 * do before it does it, and it has to be safe to run twice.
 */
class JiraImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->owner = User::create(['name' => 'Olga Owner', 'email' => 'olga@example.com', 'password' => bcrypt('secret')]);
        $this->colleague = User::create(['name' => 'Colin Colleague', 'email' => 'colin@example.com', 'password' => bcrypt('secret')]);

        config([
            'services.jira.url' => 'https://example.atlassian.net',
            'services.jira.user' => 'importer@example.com',
            'services.jira.token' => 'not-a-real-token',
        ]);
    }

    // --- the fake Jira -------------------------------------------------------

    /** @var array<int, array<string, mixed>> */
    private array $jiraIssues = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $jiraComments = [];

    private bool $faked = false;

    /**
     * Stand in for Jira.
     *
     * The stub answers from these two properties rather than being registered
     * afresh each time: Http::fake() merges what it is given with what is
     * already there and the earliest match wins, so a second registration
     * would be ignored — and the tests that matter most here are the ones
     * that import, change something in Jira, and import again.
     *
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, array<int, array<string, mixed>>>  $comments
     */
    private function fakeJira(array $issues, array $comments = []): void
    {
        $this->jiraIssues = $issues;
        $this->jiraComments = $comments;

        if ($this->faked) {
            return;
        }

        $this->faked = true;

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/rest/api/3/myself')) {
                return Http::response(['displayName' => 'Importer Bot']);
            }

            if (str_contains($url, '/rest/api/3/project/')) {
                return Http::response([
                    'key' => 'AO',
                    'name' => 'Acquirer Onboarding',
                    'description' => 'Onboarding new acquirers.',
                ]);
            }

            if (str_contains($url, '/rest/api/3/search/jql')) {
                return Http::response(['issues' => $this->jiraIssues]);
            }

            if (preg_match('#/rest/api/3/issue/([A-Z]+-\d+)/comment#', $url, $matches)) {
                $comments = $this->jiraComments[$matches[1]] ?? [];

                return Http::response(['comments' => $comments, 'total' => count($comments)]);
            }

            return Http::response(['error' => 'unexpected request to '.$url], 404);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function issue(string $key, array $overrides = []): array
    {
        return array_replace_recursive([
            'key' => $key,
            'renderedFields' => ['description' => '<p>Wire up the sandbox.</p>'],
            'fields' => [
                'summary' => 'Onboard the acquirer',
                'description' => null,
                'status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']],
                'priority' => ['name' => 'Medium'],
                'assignee' => ['displayName' => 'Colin Colleague', 'emailAddress' => 'colin@example.com', 'accountId' => 'a1'],
                'reporter' => ['displayName' => 'Olga Owner', 'emailAddress' => 'olga@example.com', 'accountId' => 'a2'],
                'created' => '2026-03-04T09:15:00.000+0200',
                'updated' => '2026-04-01T11:00:00.000+0300',
                'duedate' => null,
                'resolutiondate' => null,
                'timeoriginalestimate' => null,
                'labels' => [],
            ],
        ], $overrides);
    }

    private function import(array $options = []): Report
    {
        return (new Importer(
            JiraClient::fromConfig(),
            new StatusMap($options['map'] ?? [], mayCreate: $options['mayCreate'] ?? true),
            $this->owner,
            dryRun: $options['dryRun'] ?? false,
            refresh: $options['refresh'] ?? false,
            userOverrides: $options['users'] ?? [],
        ))->run('AO');
    }

    // --- the project ---------------------------------------------------------

    public function test_the_project_lands_with_the_named_owner(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->import();

        $project = Project::where('external_key', 'AO')->first();

        $this->assertNotNull($project);
        $this->assertSame('Acquirer Onboarding', $project->name);
        $this->assertSame($this->owner->id, $project->user_id);
        $this->assertSame('jira', $project->external_source);
        $this->assertSame('https://example.atlassian.net/browse/AO', $project->external_url);
    }

    public function test_everyone_on_an_issue_ends_up_on_the_project_team(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->import();

        $project = Project::where('external_key', 'AO')->first();

        // Otherwise the assignee cannot open the task they were given.
        $this->assertTrue($project->users->contains('id', $this->colleague->id));
    }

    // --- issues --------------------------------------------------------------

    public function test_an_issue_becomes_a_task_with_its_own_history(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'summary' => 'Onboard Acme Bank',
            'timeoriginalestimate' => 9000,
            'duedate' => '2026-05-01',
        ]])]);

        $this->import();

        $task = Task::where('external_key', 'AO-1')->first();

        $this->assertNotNull($task);
        $this->assertSame('Onboard Acme Bank', $task->title);
        $this->assertSame('in_progress', $task->status);
        $this->assertStringContainsString('Wire up the sandbox.', $task->description);
        $this->assertSame(2.5, (float) $task->estimated_hours);
        $this->assertSame('2026-05-01', $task->due_date);
        $this->assertSame('https://example.atlassian.net/browse/AO-1', $task->external_url);

        // Jira's dates, not this afternoon's — the archive counts from these.
        $this->assertSame('2026-03-04', $task->created_at->setTimezone('Europe/Riga')->toDateString());
    }

    public function test_the_assignee_and_the_reporter_are_kept_apart(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->import();

        $task = Task::where('external_key', 'AO-1')->first();

        $this->assertSame($this->colleague->id, $task->user_id);
        $this->assertSame($this->owner->id, $task->created_by);
        // "Assigned to" is the first name on the assignee list, always.
        $this->assertTrue($task->assignees->contains('id', $this->colleague->id));
    }

    public function test_a_resolved_issue_carries_the_date_it_was_resolved(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'status' => ['name' => 'Complete', 'statusCategory' => ['key' => 'done']],
            'resolutiondate' => '2026-03-20T16:40:00.000+0200',
        ]])]);

        $this->import();

        $task = Task::where('external_key', 'AO-1')->first();

        $this->assertSame('completed', $task->status);
        $this->assertSame('2026-03-20', $task->completed_at->setTimezone('Europe/Riga')->toDateString());
    }

    public function test_labels_are_written_down_rather_than_dropped(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => ['labels' => ['psp', 'q2']]])]);

        $this->import();

        $this->assertStringContainsString('psp, q2', Task::where('external_key', 'AO-1')->first()->description);
    }

    // --- people --------------------------------------------------------------

    public function test_a_jira_account_with_nobody_here_falls_to_the_owner_and_is_reported(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'assignee' => ['displayName' => 'Gone Away', 'emailAddress' => 'gone@elsewhere.com', 'accountId' => 'zz'],
        ]])]);

        $report = $this->import();

        $task = Task::where('external_key', 'AO-1')->first();

        // Inventing an account nobody can log into would be worse.
        $this->assertSame($this->owner->id, $task->user_id);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('Gone Away', implode(' ', $report->warnings));
    }

    public function test_a_jira_email_can_be_pointed_at_a_different_account_here(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'assignee' => ['displayName' => 'Old Address', 'emailAddress' => 'old@elsewhere.com', 'accountId' => 'zz'],
        ]])]);

        $this->import(['users' => ['old@elsewhere.com' => 'colin@example.com']]);

        $this->assertSame($this->colleague->id, Task::where('external_key', 'AO-1')->first()->user_id);
    }

    public function test_someone_whose_email_jira_hides_is_matched_by_name(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'assignee' => ['displayName' => 'Colin Colleague', 'accountId' => 'a1'],
        ]])]);

        $this->import();

        $this->assertSame($this->colleague->id, Task::where('external_key', 'AO-1')->first()->user_id);
    }

    // --- statuses ------------------------------------------------------------

    public function test_an_unknown_column_is_added_rather_than_guessed_at(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'status' => ['name' => 'Rejected', 'statusCategory' => ['key' => 'indeterminate']],
        ]])]);

        $report = $this->import();

        $this->assertSame('rejected', Task::where('external_key', 'AO-1')->first()->status);
        $this->assertNotNull(TaskStatus::where('key', 'rejected')->first());
        $this->assertContains('Rejected', $report->newStatuses);
        // Not finished work — it must not archive itself a month from now.
        $this->assertFalse(TaskStatus::where('key', 'rejected')->first()->is_completed);
    }

    public function test_an_operator_can_say_where_a_column_belongs(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'status' => ['name' => 'Acquirer', 'statusCategory' => ['key' => 'indeterminate']],
        ]])]);

        $this->import(['map' => ['Acquirer' => 'in_review']]);

        $this->assertSame('in_review', Task::where('external_key', 'AO-1')->first()->status);
        $this->assertNull(TaskStatus::where('key', 'acquirer')->first());
    }

    public function test_without_new_columns_an_unknown_status_folds_onto_its_category(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'status' => ['name' => 'Rejected', 'statusCategory' => ['key' => 'done']],
        ]])]);

        $this->import(['mayCreate' => false]);

        $this->assertSame('completed', Task::where('external_key', 'AO-1')->first()->status);
        $this->assertNull(TaskStatus::where('key', 'rejected')->first());
    }

    public function test_a_status_named_differently_but_meaning_the_same_is_not_duplicated(): void
    {
        $this->fakeJira([
            $this->issue('AO-1', ['fields' => ['status' => ['name' => 'Done', 'statusCategory' => ['key' => 'done']]]]),
            $this->issue('AO-2', ['fields' => ['status' => ['name' => 'Paused', 'statusCategory' => ['key' => 'indeterminate']]]]),
        ]);

        $this->import();

        $this->assertSame('completed', Task::where('external_key', 'AO-1')->first()->status);
        $this->assertSame('on_hold', Task::where('external_key', 'AO-2')->first()->status);
        $this->assertSame(5, TaskStatus::count());
    }

    // --- comments ------------------------------------------------------------

    public function test_the_discussion_comes_across_with_its_authors_and_dates(): void
    {
        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            [
                'id' => '10101',
                'author' => ['displayName' => 'Colin Colleague', 'emailAddress' => 'colin@example.com'],
                'renderedBody' => '<p>Docs received.</p>',
                'created' => '2026-03-05T10:00:00.000+0200',
                'updated' => '2026-03-05T10:00:00.000+0200',
            ],
        ]]);

        $this->import();

        $comment = TaskComment::first();

        $this->assertNotNull($comment);
        $this->assertSame($this->colleague->id, $comment->user_id);
        $this->assertStringContainsString('Docs received.', $comment->body);
        $this->assertSame('2026-03-05', $comment->created_at->setTimezone('Europe/Riga')->toDateString());
    }

    public function test_a_comment_jira_never_rendered_is_converted_from_its_own_format(): void
    {
        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            [
                'id' => '10101',
                'author' => ['emailAddress' => 'colin@example.com', 'displayName' => 'Colin Colleague'],
                'body' => ['type' => 'doc', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Chased the bank.']]],
                ]],
                'created' => '2026-03-05T10:00:00.000+0200',
            ],
        ]]);

        $this->import();

        $this->assertStringContainsString('Chased the bank.', TaskComment::first()->body);
    }

    public function test_importing_a_comment_does_not_email_the_whole_task(): void
    {
        Notification::fake();

        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            [
                'id' => '10101',
                'author' => ['emailAddress' => 'colin@example.com', 'displayName' => 'Colin Colleague'],
                'renderedBody' => '<p>Docs received.</p>',
                'created' => '2026-03-05T10:00:00.000+0200',
            ],
        ]]);

        $this->import();

        // Two years of Jira history arriving in everyone's inbox at once is a
        // way to lose an inbox.
        Notification::assertNothingSent();
    }

    // --- running it twice ----------------------------------------------------

    public function test_a_second_run_does_not_make_a_second_copy(): void
    {
        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            ['id' => '1', 'author' => ['emailAddress' => 'colin@example.com'], 'renderedBody' => '<p>One.</p>', 'created' => '2026-03-05T10:00:00.000+0200'],
        ]]);

        $this->import();
        $report = $this->import();

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Task::count());
        $this->assertSame(1, TaskComment::count());
        $this->assertSame(1, $report->tasksSkipped);
        $this->assertSame(1, $report->commentsSkipped);
    }

    public function test_a_second_run_brings_the_comments_added_since(): void
    {
        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            ['id' => '1', 'author' => ['emailAddress' => 'colin@example.com'], 'renderedBody' => '<p>One.</p>', 'created' => '2026-03-05T10:00:00.000+0200'],
        ]]);

        $this->import();

        $this->fakeJira([$this->issue('AO-1')], ['AO-1' => [
            ['id' => '1', 'author' => ['emailAddress' => 'colin@example.com'], 'renderedBody' => '<p>One.</p>', 'created' => '2026-03-05T10:00:00.000+0200'],
            ['id' => '2', 'author' => ['emailAddress' => 'colin@example.com'], 'renderedBody' => '<p>Two.</p>', 'created' => '2026-03-06T10:00:00.000+0200'],
        ]]);

        $this->import();

        $this->assertSame(2, TaskComment::count());
    }

    public function test_a_refresh_brings_the_issue_itself_up_to_date(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);
        $this->import();

        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'summary' => 'Onboard Acme Bank (renamed)',
            'status' => ['name' => 'Complete', 'statusCategory' => ['key' => 'done']],
        ]])]);
        $this->import(['refresh' => true]);

        $task = Task::where('external_key', 'AO-1')->first();

        $this->assertSame(1, Task::count());
        $this->assertSame('Onboard Acme Bank (renamed)', $task->title);
        $this->assertSame('completed', $task->status);
    }

    // --- the rehearsal -------------------------------------------------------

    public function test_a_dry_run_writes_nothing_at_all(): void
    {
        $this->fakeJira([$this->issue('AO-1'), $this->issue('AO-2')], ['AO-1' => [
            ['id' => '1', 'author' => ['emailAddress' => 'colin@example.com'], 'renderedBody' => '<p>One.</p>', 'created' => '2026-03-05T10:00:00.000+0200'],
        ]]);

        $report = $this->import(['dryRun' => true]);

        $this->assertSame(0, Project::count());
        $this->assertSame(0, Task::count());
        $this->assertSame(0, TaskComment::count());

        // …and still says exactly what it would have done.
        $this->assertSame(2, $report->tasksCreated);
        $this->assertSame(1, $report->commentsCreated);
        $this->assertSame('Acquirer Onboarding', $report->projectName);
    }

    public function test_a_dry_run_does_not_add_a_column_to_everyone_elses_board(): void
    {
        $this->fakeJira([$this->issue('AO-1', ['fields' => [
            'status' => ['name' => 'Rejected', 'statusCategory' => ['key' => 'indeterminate']],
        ]])]);

        $report = $this->import(['dryRun' => true]);

        $this->assertContains('Rejected', $report->newStatuses);
        $this->assertSame(5, TaskStatus::count());
    }

    // --- the command itself --------------------------------------------------

    public function test_the_command_refuses_to_run_without_an_owner(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->artisan('jira:import AO')
            ->expectsOutputToContain('Say who owns the imported project')
            ->assertExitCode(1);
    }

    public function test_the_command_refuses_an_owner_who_does_not_exist(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->artisan('jira:import AO --owner=nobody@example.com')
            ->assertExitCode(1);

        $this->assertSame(0, Project::count());
    }

    public function test_the_command_imports_and_reports(): void
    {
        $this->fakeJira([$this->issue('AO-1')]);

        $this->artisan('jira:import AO --owner=olga@example.com --map="In Progress=in_review"')
            ->assertExitCode(0);

        $this->assertSame('in_review', Task::where('external_key', 'AO-1')->first()->status);
    }

    public function test_the_command_says_when_jira_is_not_configured(): void
    {
        config(['services.jira.token' => null]);

        $this->artisan('jira:import AO --owner=olga@example.com')
            ->expectsOutputToContain('Jira is not configured')
            ->assertExitCode(1);
    }
}
