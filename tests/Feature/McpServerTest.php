<?php

namespace Tests\Feature;

use App\Mcp\Servers\TaskManagerServer;
use App\Mcp\Tools\AddChecklistItem;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\AssignTask;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\ReadAttachment;
use App\Mcp\Tools\SetChecklistItem;
use App\Mcp\Tools\UpdateTaskStatus;
use App\Models\File;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The task manager as an AI agent sees it.
 *
 * The one rule everything here defends: an agent acts as the person whose
 * token it holds, and gets exactly that person's view — never more. The
 * tools are a convenience; the boundary is the point.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $outsider;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->member = User::create(['name' => 'Mia Member', 'email' => 'mia@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Oz Outsider', 'email' => 'oz@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->member->id, 'name' => 'Onboarding', 'status' => 'in_progress']);
        $this->task = Task::create([
            'user_id' => $this->member->id,
            'project_id' => $this->project->id,
            'title' => 'Verify the merchant',
            'description' => '<p>Check the KYC pack and the site.</p>',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    // --- the boundary ---------------------------------------------------------

    public function test_every_tool_answers_to_its_published_name(): void
    {
        // The names an MCP client will call — a rename here breaks every
        // connected agent, which deserves a red test, not a surprise.
        $this->assertSame('get-task', (new GetTask)->name());
        $this->assertSame('list-tasks', (new ListTasks)->name());
        $this->assertSame('create-task', (new CreateTask)->name());
        $this->assertSame('add-comment', (new AddComment)->name());
        $this->assertSame('update-task-status', (new UpdateTaskStatus)->name());
        $this->assertSame('assign-task', (new AssignTask)->name());
        $this->assertSame('add-checklist-item', (new AddChecklistItem)->name());
        $this->assertSame('set-checklist-item', (new SetChecklistItem)->name());
        $this->assertSame('read-attachment', (new ReadAttachment)->name());
        $this->assertSame('get-project', (new GetProject)->name());
    }

    public function test_a_task_outside_your_projects_does_not_exist_for_you(): void
    {
        // Not "forbidden" — the same answer as for a task that was never
        // there, so ids cannot be probed for what exists.
        TaskManagerServer::actingAs($this->outsider)
            ->tool(GetTask::class, ['task' => (string) $this->task->id])
            ->assertHasErrors()
            ->assertSee('not visible');
    }

    public function test_an_outsider_sees_an_empty_board(): void
    {
        TaskManagerServer::actingAs($this->outsider)
            ->tool(ListTasks::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
    }

    public function test_an_outsider_cannot_comment(): void
    {
        TaskManagerServer::actingAs($this->outsider)
            ->tool(AddComment::class, ['task' => (string) $this->task->id, 'text' => 'Sneaky'])
            ->assertHasErrors();

        $this->assertSame(0, $this->task->comments()->count());
    }

    // --- reading --------------------------------------------------------------

    public function test_get_task_reads_a_task_from_a_url_the_way_a_person_pastes_it(): void
    {
        $response = TaskManagerServer::actingAs($this->member)
            ->tool(GetTask::class, ['task' => 'https://tasks.example.com/tasks/'.$this->task->id]);

        $response->assertOk()
            ->assertSee('Verify the merchant')
            ->assertSee('Check the KYC pack');
    }

    public function test_get_task_accepts_the_printed_key(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(GetTask::class, ['task' => sprintf('TASK-%04d', $this->task->id)])
            ->assertOk()
            ->assertSee('Verify the merchant');
    }

    public function test_list_tasks_filters_by_tag(): void
    {
        $this->task->tags()->attach(Tag::named('urgent'));
        Task::create(['user_id' => $this->member->id, 'project_id' => $this->project->id,
            'title' => 'Untagged chore', 'priority' => 'low', 'status' => 'to_do']);

        TaskManagerServer::actingAs($this->member)
            ->tool(ListTasks::class, ['tag' => 'urgent'])
            ->assertOk()
            ->assertSee('Verify the merchant')
            ->assertDontSee('Untagged chore');
    }

    public function test_get_project_shows_the_board_and_its_fields(): void
    {
        $this->project->fields()->create(['name' => 'Acquirer', 'key' => 'acquirer',
            'type' => 'select', 'options' => ['XSELL', 'MADFIN'], 'sort_order' => 1]);

        TaskManagerServer::actingAs($this->member)
            ->tool(GetProject::class, ['project' => 'Onboarding'])
            ->assertOk()
            ->assertSee('Acquirer')
            ->assertSee('XSELL');
    }

    // --- writing --------------------------------------------------------------

    public function test_a_comment_lands_in_the_discussion_under_the_agents_person(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(AddComment::class, [
                'task' => (string) $this->task->id,
                'text' => "Site checked.\n\nTwo issues found <script>alert(1)</script>",
            ])
            ->assertOk();

        $comment = $this->task->comments()->first();

        $this->assertSame($this->member->id, $comment->user_id);
        // Plain text became paragraphs; markup became harmless text.
        $this->assertStringContainsString('<p>Site checked.</p>', $comment->body);
        $this->assertStringNotContainsString('<script>', $comment->body);
    }

    public function test_moving_a_task_respects_the_boards_own_columns(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(UpdateTaskStatus::class, ['task' => (string) $this->task->id, 'status' => 'in_progress'])
            ->assertOk();

        $this->assertSame('in_progress', $this->task->fresh()->status);
    }

    public function test_a_status_the_board_does_not_offer_is_refused_by_name(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(UpdateTaskStatus::class, ['task' => (string) $this->task->id, 'status' => 'shipped'])
            ->assertHasErrors()
            ->assertSee('shipped');

        $this->assertSame('to_do', $this->task->fresh()->status);
    }

    // --- creating tasks -----------------------------------------------------------

    public function test_an_agent_files_a_task_like_the_form_does(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(CreateTask::class, [
                'project' => 'Onboarding',
                'title' => 'Chase the missing KYC pack',
                'description' => "First paragraph.\n\nSecond <script>alert(1)</script>",
                'priority' => 'high',
                'due_date' => '2026-09-01',
                'tags' => 'urgent, kyc',
            ])
            ->assertOk()
            ->assertSee('TASK-');

        $task = Task::where('title', 'Chase the missing KYC pack')->first();

        $this->assertNotNull($task);
        // Owned by the acting person, in the board's first column.
        $this->assertSame($this->member->id, $task->user_id);
        $this->assertSame('to_do', $task->status);
        // Plain text became escaped paragraphs, never markup.
        $this->assertStringContainsString('<p>First paragraph.</p>', $task->description);
        $this->assertStringNotContainsString('<script>', $task->description);
        $this->assertEqualsCanonicalizing(['urgent', 'kyc'], $task->tags->pluck('name')->all());
        // And it entered history like any created task.
        $this->assertSame(1, $task->activities()->where('event', 'created')->count());
    }

    public function test_a_task_filed_for_a_colleague_belongs_to_them(): void
    {
        $this->project->users()->attach($this->outsider->id);

        TaskManagerServer::actingAs($this->member)
            ->tool(CreateTask::class, [
                'project' => 'Onboarding',
                'title' => 'Handover piece',
                'assignee' => 'oz@example.com',
            ])
            ->assertOk();

        $task = Task::where('title', 'Handover piece')->first();

        $this->assertSame($this->outsider->id, $task->user_id);
        $this->assertTrue($task->assignees->contains('id', $this->outsider->id));
    }

    public function test_a_task_cannot_be_filed_into_somebody_elses_project(): void
    {
        TaskManagerServer::actingAs($this->outsider)
            ->tool(CreateTask::class, ['project' => 'Onboarding', 'title' => 'Sneaky'])
            ->assertHasErrors();

        $this->assertNull(Task::where('title', 'Sneaky')->first());
    }

    public function test_a_task_cannot_be_handed_to_somebody_outside_the_project(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(CreateTask::class, [
                'project' => 'Onboarding',
                'title' => 'Landing in a locked room',
                'assignee' => 'oz@example.com',
            ])
            ->assertHasErrors()
            ->assertSee('not a member');

        $this->assertNull(Task::where('title', 'Landing in a locked room')->first());
    }

    // --- checklists -----------------------------------------------------------------

    public function test_an_agent_keeps_a_checklist(): void
    {
        TaskManagerServer::actingAs($this->member)
            ->tool(AddChecklistItem::class, ['task' => (string) $this->task->id, 'name' => 'Check the site'])
            ->assertOk();

        TaskManagerServer::actingAs($this->member)
            ->tool(SetChecklistItem::class, ['task' => (string) $this->task->id, 'item' => 'Check the site'])
            ->assertOk();

        $item = $this->task->checklistItems()->first();

        $this->assertTrue((bool) $item->completed);
        // Both moves are in the history, attributed.
        $this->assertSame(1, $this->task->activities()->where('event', 'checklist_added')->count());
        $this->assertSame(1, $this->task->activities()->where('event', 'checklist_done')->count());
    }

    public function test_a_missing_checklist_item_is_answered_with_what_exists(): void
    {
        $this->task->checklistItems()->create(['name' => 'Only step']);

        TaskManagerServer::actingAs($this->member)
            ->tool(SetChecklistItem::class, ['task' => (string) $this->task->id, 'item' => 'No such step'])
            ->assertHasErrors()
            ->assertSee('Only step');
    }

    public function test_an_outsider_cannot_touch_the_checklist(): void
    {
        TaskManagerServer::actingAs($this->outsider)
            ->tool(AddChecklistItem::class, ['task' => (string) $this->task->id, 'name' => 'Sneaky step'])
            ->assertHasErrors();

        $this->assertSame(0, $this->task->checklistItems()->count());
    }

    // --- assignment -------------------------------------------------------------------

    public function test_the_owner_hands_the_task_to_a_project_member(): void
    {
        $this->project->users()->attach($this->outsider->id);

        TaskManagerServer::actingAs($this->member)
            ->tool(AssignTask::class, ['task' => (string) $this->task->id, 'person' => 'Oz Outsider'])
            ->assertOk();

        $this->assertTrue($this->task->assignees()->get()->contains('id', $this->outsider->id));

        TaskManagerServer::actingAs($this->member)
            ->tool(AssignTask::class, ['task' => (string) $this->task->id, 'person' => 'oz@example.com', 'remove' => true])
            ->assertOk();

        $this->assertFalse($this->task->assignees()->get()->contains('id', $this->outsider->id));
    }

    public function test_assignment_respects_the_managers_only_rule(): void
    {
        // A member of the project who neither owns the task nor manages the
        // project may read it — and may not rearrange who works on it.
        $collaborator = User::create(['name' => 'Col Laborator', 'email' => 'col@example.com', 'password' => bcrypt('secret')]);
        $this->project->users()->attach($collaborator->id);

        TaskManagerServer::actingAs($collaborator)
            ->tool(AssignTask::class, ['task' => (string) $this->task->id, 'person' => 'me'])
            ->assertHasErrors()
            ->assertSee('owner or a project manager');
    }

    public function test_an_ambiguous_name_is_refused_with_the_candidates(): void
    {
        $this->project->users()->attach($this->outsider->id);
        $twin = User::create(['name' => 'Oz Outsider', 'email' => 'oz2@example.com', 'password' => bcrypt('secret')]);
        $this->project->users()->attach($twin->id);

        TaskManagerServer::actingAs($this->member)
            ->tool(AssignTask::class, ['task' => (string) $this->task->id, 'person' => 'Oz'])
            ->assertHasErrors()
            ->assertSee('oz2@example.com');

        $this->assertFalse($this->task->assignees()->get()->contains('id', $twin->id));
    }

    // --- attachments ------------------------------------------------------------

    public function test_a_text_attachment_comes_back_as_its_contents(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/checklist.txt', "1. KYC\n2. Site");

        $file = File::create(['user_id' => $this->member->id, 'task_id' => $this->task->id,
            'name' => 'checklist.txt', 'original_name' => 'checklist.txt',
            'path' => 'uploads/checklist.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 14]);

        TaskManagerServer::actingAs($this->member)
            ->tool(ReadAttachment::class, ['attachment_id' => $file->id])
            ->assertOk()
            ->assertSee('KYC');
    }

    public function test_a_format_agents_cannot_read_is_named_not_dumped(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/notes.docx', 'binary-blob');

        $file = File::create(['user_id' => $this->member->id, 'task_id' => $this->task->id,
            'name' => 'notes.docx', 'original_name' => 'notes.docx',
            'path' => 'uploads/notes.docx', 'type' => 'document',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 11]);

        TaskManagerServer::actingAs($this->member)
            ->tool(ReadAttachment::class, ['attachment_id' => $file->id])
            ->assertHasErrors()
            ->assertSee('notes.docx');
    }

    public function test_someone_elses_attachment_does_not_exist_for_you(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/secret.txt', 'do not leak');

        $file = File::create(['user_id' => $this->member->id, 'task_id' => $this->task->id,
            'name' => 'secret.txt', 'original_name' => 'secret.txt',
            'path' => 'uploads/secret.txt', 'type' => 'document',
            'mime_type' => 'text/plain', 'size' => 11]);

        TaskManagerServer::actingAs($this->outsider)
            ->tool(ReadAttachment::class, ['attachment_id' => $file->id])
            ->assertHasErrors();
    }

    // --- the tokens page --------------------------------------------------------

    public function test_a_person_creates_a_token_and_sees_it_exactly_once(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('profile.agents.store'), ['name' => 'Claude on the laptop']);

        $response->assertRedirect(route('profile.agents'));

        $this->assertSame(1, $this->member->tokens()->count());

        // The plain token rides the redirect once; the page after shows it.
        $this->followRedirects($response)->assertSee('copy it now');
    }

    public function test_revoking_a_token_removes_it(): void
    {
        $this->member->createToken('Old agent');
        $token = $this->member->tokens()->first();

        $this->actingAs($this->member)
            ->delete(route('profile.agents.destroy', $token->id))
            ->assertRedirect();

        $this->assertSame(0, $this->member->tokens()->count());
    }

    public function test_nobody_revokes_anybody_elses_token(): void
    {
        $this->member->createToken('Mine');
        $token = $this->member->tokens()->first();

        $this->actingAs($this->outsider)
            ->delete(route('profile.agents.destroy', $token->id))
            ->assertNotFound();

        $this->assertSame(1, $this->member->tokens()->count());
    }

    public function test_the_endpoint_requires_a_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1])
            ->assertUnauthorized();
    }
}
