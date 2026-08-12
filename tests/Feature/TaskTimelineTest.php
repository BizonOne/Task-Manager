<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every change to a task is recorded, so the task page can show its full
 * history — who changed what, when — alongside the discussion.
 */
class TaskTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Proj', 'status' => 'in_progress']);
    }

    private function task(array $overrides = []): Task
    {
        return Task::create(array_merge([
            'user_id' => $this->owner->id,
            'project_id' => $this->project->id,
            'title' => 'Do it',
            'priority' => 'medium',
            'status' => 'to_do',
        ], $overrides));
    }

    // --- the checklist ---------------------------------------------------------

    public function test_every_checklist_action_lands_in_the_history(): void
    {
        $task = $this->task();
        $this->actingAs($this->owner);

        // The whole life of one item: added, done, second thoughts, reworded, gone.
        $item = $task->checklistItems()->create(['name' => 'Check the KYC pack']);
        $item->update(['completed' => 1]);
        $item->update(['completed' => 0]);
        $item->update(['name' => 'Check the KYC pack twice']);
        $item->delete();

        $events = TaskActivity::where('task_id', $task->id)
            ->whereIn('event', [
                TaskActivity::EVENT_CHECKLIST_ADDED,
                TaskActivity::EVENT_CHECKLIST_DONE,
                TaskActivity::EVENT_CHECKLIST_UNDONE,
                TaskActivity::EVENT_CHECKLIST_RENAMED,
                TaskActivity::EVENT_CHECKLIST_REMOVED,
            ])
            ->orderBy('id')
            ->get();

        $this->assertSame([
            TaskActivity::EVENT_CHECKLIST_ADDED,
            TaskActivity::EVENT_CHECKLIST_DONE,
            TaskActivity::EVENT_CHECKLIST_UNDONE,
            TaskActivity::EVENT_CHECKLIST_RENAMED,
            TaskActivity::EVENT_CHECKLIST_REMOVED,
        ], $events->pluck('event')->all());

        // Each entry names its actor and reads like a sentence.
        $this->assertSame('added a checklist item: “Check the KYC pack”', $events[0]->description);
        $this->assertSame('checked off “Check the KYC pack”', $events[1]->description);
        $this->assertSame('unchecked “Check the KYC pack”', $events[2]->description);
        $this->assertSame('reworded a checklist item to “Check the KYC pack twice”', $events[3]->description);
        // The wording survives the row it described being gone.
        $this->assertSame('removed a checklist item: “Check the KYC pack twice”', $events[4]->description);
        $this->assertSame($this->owner->id, $events[0]->user_id);
    }

    public function test_checklist_history_is_written_from_the_web_routes_too(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner)
            ->postJson(route('checklist-items.store'), ['task_id' => $task->id, 'name' => 'Ship it'])
            ->assertSuccessful();

        $item = $task->checklistItems()->first();

        $this->actingAs($this->owner)
            ->get(route('checklist-items.update-status', $item))
            ->assertSuccessful();

        $this->actingAs($this->owner)
            ->deleteJson(route('checklist-items.destroy', $item))
            ->assertSuccessful();

        $this->assertSame(3, TaskActivity::where('task_id', $task->id)
            ->where('event', 'like', 'checklist_%')
            ->count());
    }

    public function test_an_outsider_cannot_touch_somebody_elses_checklist(): void
    {
        $task = $this->task();
        $item = $task->checklistItems()->create(['name' => 'Private step']);

        $outsider = User::create(['name' => 'Out Sider', 'email' => 'out@example.com', 'password' => bcrypt('secret')]);

        // Not on the project, not assigned — the checklist is not theirs.
        $this->actingAs($outsider)
            ->postJson(route('checklist-items.store'), ['task_id' => $task->id, 'name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('checklist-items.update-status', $item))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->deleteJson(route('checklist-items.destroy', $item))
            ->assertForbidden();

        $this->assertSame(1, $task->checklistItems()->count());
        $this->assertFalse((bool) $item->fresh()->completed);
    }

    public function test_creating_a_task_records_the_first_entry(): void
    {
        $task = $this->task();

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'event' => TaskActivity::EVENT_CREATED,
        ]);
    }

    public function test_each_changed_field_is_recorded_separately_with_before_and_after(): void
    {
        $task = $this->task();
        $this->actingAs($this->owner);

        $task->update(['status' => 'in_progress', 'priority' => 'high']);

        $changes = TaskActivity::where('task_id', $task->id)
            ->where('event', TaskActivity::EVENT_UPDATED)
            ->get();

        $this->assertCount(2, $changes);

        $status = $changes->firstWhere('field', 'status');
        $this->assertSame('to_do', $status->old_value);
        $this->assertSame('in_progress', $status->new_value);
        // Rendered with the admin-managed labels, not raw keys.
        $this->assertSame('changed status from To Do to In Progress', $status->description);
        $this->assertSame($this->owner->id, $status->user_id);

        $priority = $changes->firstWhere('field', 'priority');
        $this->assertSame('changed priority from Medium to High', $priority->description);
    }

    public function test_untracked_columns_do_not_create_noise(): void
    {
        $task = $this->task();
        $before = TaskActivity::where('task_id', $task->id)->count();

        $task->touch();

        $this->assertSame($before, TaskActivity::where('task_id', $task->id)->count());
    }

    public function test_setting_and_clearing_a_value_reads_naturally(): void
    {
        $task = $this->task();

        $task->update(['due_date' => '2026-08-15']);
        $set = TaskActivity::where('task_id', $task->id)->where('field', 'due_date')->latest('id')->first();
        $this->assertSame('set due date to 15 Aug 2026', $set->description);

        $task->update(['due_date' => null]);
        $cleared = TaskActivity::where('task_id', $task->id)->where('field', 'due_date')->latest('id')->first();
        $this->assertSame('cleared due date', $cleared->description);
    }

    public function test_status_changes_from_the_board_are_recorded(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner)
            ->postJson("/tasks/{$task->id}/update-status", ['status' => 'completed'])
            ->assertSuccessful();

        $entry = TaskActivity::where('task_id', $task->id)->where('field', 'status')->firstOrFail();
        $this->assertSame('completed', $entry->new_value);
        $this->assertSame($this->owner->id, $entry->user_id);
    }

    public function test_assigning_and_unassigning_are_recorded_with_the_persons_name(): void
    {
        Notification::fake();
        $task = $this->task();
        $mate = User::create(['name' => 'Team Mate', 'email' => 'mate@example.com', 'password' => bcrypt('secret')]);

        $this->actingAs($this->owner)
            ->postJson("/tasks/{$task->id}/assignees", ['user_id' => $mate->id])
            ->assertSuccessful();

        $assigned = TaskActivity::where('task_id', $task->id)->where('event', TaskActivity::EVENT_ASSIGNED)->firstOrFail();
        $this->assertSame('assigned Team Mate', $assigned->description);

        $this->actingAs($this->owner)
            ->deleteJson("/tasks/{$task->id}/assignees/{$mate->id}")
            ->assertSuccessful();

        $unassigned = TaskActivity::where('task_id', $task->id)->where('event', TaskActivity::EVENT_UNASSIGNED)->firstOrFail();
        $this->assertSame('unassigned Team Mate', $unassigned->description);
    }

    public function test_comments_appear_in_the_timeline_with_their_text(): void
    {
        $task = $this->task();
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->owner->id, 'body' => 'Looks good to me']);

        $timeline = $task->fresh()->timeline();
        $comment = $timeline->firstWhere('type', 'comment');

        $this->assertNotNull($comment);
        $this->assertSame('Looks good to me', $comment['body']);
        $this->assertSame('Owner', $comment['actor']);

        // The bare "commented" marker is not shown twice alongside the comment.
        $this->assertCount(0, $timeline->where('text', 'commented')->where('type', 'activity'));
    }

    public function test_deleting_a_comment_leaves_a_trace(): void
    {
        $task = $this->task();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $this->owner->id, 'body' => 'oops']);

        $this->actingAs($this->owner)->deleteJson("/comments/{$comment->id}")->assertSuccessful();

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'event' => TaskActivity::EVENT_COMMENT_DELETED,
        ]);
    }

    public function test_the_timeline_is_chronological_and_mixes_changes_with_comments(): void
    {
        $task = $this->task();
        $this->actingAs($this->owner);

        $task->update(['status' => 'in_progress']);
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->owner->id, 'body' => 'Started']);
        $task->update(['status' => 'completed']);

        $timeline = $task->fresh()->timeline();
        $texts = $timeline->pluck('text')->all();

        $this->assertSame('created this task', $texts[0]);
        $this->assertContains('commented', $texts);
        $this->assertSame('changed status from In Progress to Completed', end($texts));

        // Sorted oldest first.
        $times = $timeline->pluck('at')->all();
        $sorted = collect($times)->sort()->values()->all();
        $this->assertEquals($sorted, $times);
    }

    public function test_the_task_page_shows_the_history(): void
    {
        $task = $this->task();
        $this->actingAs($this->owner);
        $task->update(['priority' => 'high']);
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->owner->id, 'body' => 'On it']);

        $this->actingAs($this->owner)->get("/tasks/{$task->id}")
            ->assertSuccessful()
            ->assertSee('History')
            ->assertSee('changed priority from Medium to High')
            ->assertSee('On it');
    }

    public function test_comments_predating_the_activity_log_still_appear(): void
    {
        $task = $this->task();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $this->owner->id, 'body' => 'Old discussion']);

        // Simulate production data: the comment exists but was written before
        // the history feature, so it has no `commented` activity row.
        TaskActivity::where('task_id', $task->id)
            ->where('event', TaskActivity::EVENT_COMMENTED)
            ->delete();

        $timeline = $task->fresh()->timeline();
        $found = $timeline->firstWhere('body', 'Old discussion');

        $this->assertNotNull($found, 'A comment without an activity row must not vanish from the timeline.');
        $this->assertSame($comment->created_at->timestamp, $found['at']->timestamp);
    }

    public function test_a_change_made_outside_a_request_is_attributed_to_the_system(): void
    {
        // No authenticated user — e.g. a console command or seeder.
        $task = $this->task();

        $entry = TaskActivity::where('task_id', $task->id)->firstOrFail();
        $this->assertNull($entry->user_id);
        $this->assertSame('System', $entry->actor_name);
    }

    public function test_the_admin_task_page_shows_the_history_relation_manager(): void
    {
        $admin = User::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => bcrypt('secret')]);
        $admin->assignRole('super_admin');
        $task = $this->task();

        $this->actingAs($admin)->get("/admin/tasks/{$task->id}/edit")
            ->assertSuccessful()
            ->assertSee('History');
    }
}
