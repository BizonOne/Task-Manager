<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The person who raised the work is the one waiting to hear that it moved.
 *
 * Also: owner and assignee are different people. Raising a task for a colleague
 * used to make *them* its owner — of work they never asked for.
 */
class TaskStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $doer;

    private User $helper;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::create(['name' => 'Ann Author', 'email' => 'ann@example.com', 'password' => bcrypt('secret')]);
        $this->doer = User::create(['name' => 'Dan Doer', 'email' => 'dan@example.com', 'password' => bcrypt('secret')]);
        $this->helper = User::create(['name' => 'Hal Helper', 'email' => 'hal@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->author->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach([$this->doer->id, $this->helper->id]);

        // Raised by Ann, for Dan.
        $this->actingAs($this->author);
        $this->task = Task::create([
            'user_id' => $this->doer->id,
            'project_id' => $this->project->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    // --- who hears about a status change ------------------------------------

    public function test_the_person_who_raised_it_hears_when_the_status_moves(): void
    {
        Notification::fake();

        $this->actingAs($this->doer)
            ->post("/tasks/{$this->task->id}/update-status", ['status' => 'in_progress'])
            ->assertSuccessful();

        Notification::assertSentTo($this->author, TaskStatusChangedNotification::class);
    }

    public function test_the_person_it_is_assigned_to_hears_too(): void
    {
        Notification::fake();

        // The author moves it; the doer is the one who needs to know.
        $this->actingAs($this->author)
            ->post("/tasks/{$this->task->id}/update-status", ['status' => 'in_progress'])
            ->assertSuccessful();

        Notification::assertSentTo($this->doer, TaskStatusChangedNotification::class);
    }

    public function test_whoever_moved_it_is_not_told_about_their_own_change(): void
    {
        Notification::fake();

        $this->actingAs($this->doer)
            ->post("/tasks/{$this->task->id}/update-status", ['status' => 'in_progress'])
            ->assertSuccessful();

        Notification::assertNotSentTo($this->doer, TaskStatusChangedNotification::class);
    }

    public function test_extra_assignees_hear_as_well(): void
    {
        $this->task->assignees()->attach($this->helper->id);
        Notification::fake();

        $this->actingAs($this->doer)
            ->post("/tasks/{$this->task->id}/update-status", ['status' => 'completed'])
            ->assertSuccessful();

        Notification::assertSentTo($this->helper, TaskStatusChangedNotification::class);
    }

    public function test_a_change_from_the_edit_form_counts_the_same(): void
    {
        Notification::fake();

        $this->actingAs($this->doer)->put("/tasks/{$this->task->id}", [
            'title' => $this->task->title,
            'priority' => 'high',
            'status' => 'completed',
            'user_id' => $this->doer->id,
        ])->assertRedirect();

        // Every path that moves a task is a path someone is waiting on.
        Notification::assertSentTo($this->author, TaskStatusChangedNotification::class);
    }

    public function test_editing_a_task_without_moving_it_tells_nobody(): void
    {
        Notification::fake();

        $this->actingAs($this->doer)->put("/tasks/{$this->task->id}", [
            'title' => 'A better title',
            'priority' => 'low',
            'status' => 'to_do',
            'user_id' => $this->doer->id,
        ])->assertRedirect();

        Notification::assertNotSentTo($this->author, TaskStatusChangedNotification::class);
    }

    public function test_a_change_made_outside_a_request_tells_nobody(): void
    {
        Notification::fake();

        auth()->logout();
        $this->task->update(['status' => 'completed']);

        Notification::assertNothingSent();
    }

    public function test_the_email_says_where_it_moved_from_and_to(): void
    {
        $this->task->update(['status' => 'in_progress']);
        $notification = new TaskStatusChangedNotification($this->task, $this->doer, 'to_do', 'in_progress');

        $mail = $notification->toMail($this->author);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Dan Doer', $mail->subject);
        $this->assertStringContainsString('In Progress', $mail->subject);
        $this->assertStringContainsString('To Do', $html);
        $this->assertStringContainsString('In Progress', $html);
    }

    public function test_finishing_a_task_says_so_in_the_email(): void
    {
        $html = (string) (new TaskStatusChangedNotification($this->task, $this->doer, 'in_progress', 'completed'))
            ->toMail($this->author)->render();

        $this->assertStringContainsString('This task is finished', $html);
    }

    public function test_the_bell_names_the_status_it_moved_to(): void
    {
        $this->actingAs($this->doer)
            ->post("/tasks/{$this->task->id}/update-status", ['status' => 'completed'])
            ->assertSuccessful();

        $this->actingAs($this->author)
            ->get('/notifications')
            ->assertSuccessful()
            ->assertSee('Dan Doer moved "Ship the invoice run" to Completed');
    }

    // --- owner is not the assignee -----------------------------------------

    public function test_raising_a_task_for_someone_does_not_make_them_its_owner(): void
    {
        $this->assertSame($this->author->id, $this->task->created_by);
        $this->assertSame($this->doer->id, $this->task->user_id);
    }

    public function test_the_task_page_shows_the_owner_and_the_assignee_separately(): void
    {
        $this->actingAs($this->author)
            ->get("/tasks/{$this->task->id}")
            ->assertSuccessful()
            ->assertSeeInOrder(['Owner', 'Ann Author', 'Assigned To', 'Dan Doer']);
    }

    public function test_reassigning_does_not_move_the_owner(): void
    {
        $this->actingAs($this->author)->put("/tasks/{$this->task->id}", [
            'title' => $this->task->title,
            'priority' => 'high',
            'status' => 'to_do',
            'user_id' => $this->helper->id,
        ])->assertRedirect();

        $fresh = $this->task->fresh();

        // Assignment moves. Ownership does not.
        $this->assertSame($this->helper->id, $fresh->user_id);
        $this->assertSame($this->author->id, $fresh->created_by);
    }

    public function test_the_board_can_be_filtered_by_who_raised_a_task(): void
    {
        $this->actingAs($this->author)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('Raised by me')
            ->assertSee('Assigned to me')
            ->assertSee('data-creator="'.$this->author->id.'"', false);
    }
}
