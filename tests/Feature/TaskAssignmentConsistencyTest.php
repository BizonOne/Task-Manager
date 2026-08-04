<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Support\TaskAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A task used to carry two independent ideas of who it was on: the `user_id`
 * named as "Assigned To", set once on the create form, and the assignee list.
 * They could name different people, and on real tasks they did.
 *
 * The list is the truth. Everything here is a guard on that.
 */
class TaskAssignmentConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $first;

    private User $second;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::create(['name' => 'Ann Author', 'email' => 'ann@example.com', 'password' => bcrypt('secret')]);
        $this->first = User::create(['name' => 'Fay First', 'email' => 'fay@example.com', 'password' => bcrypt('secret')]);
        $this->second = User::create(['name' => 'Sam Second', 'email' => 'sam@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->author->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach([$this->first->id, $this->second->id]);
    }

    private function task(?User $assignedTo = null): Task
    {
        $this->actingAs($this->author);

        return Task::create([
            'user_id' => ($assignedTo ?? $this->first)->id,
            'project_id' => $this->project->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    // --- the two ideas cannot drift apart -----------------------------------

    public function test_the_person_named_on_the_create_form_is_on_the_assignee_list(): void
    {
        $task = $this->task($this->first);

        // The Assignees card used to be empty while the page said "assigned to
        // Fay", and everything drifted from there.
        $this->assertTrue($task->assignees->contains('id', $this->first->id));
    }

    public function test_changing_assigned_to_puts_that_person_on_the_list(): void
    {
        $task = $this->task($this->first);

        $this->actingAs($this->author)->put("/tasks/{$task->id}", [
            'title' => $task->title,
            'priority' => 'high',
            'status' => 'to_do',
            'user_id' => $this->second->id,
        ])->assertRedirect();

        $this->assertTrue($task->fresh()->assignees->contains('id', $this->second->id));
    }

    public function test_the_named_assignee_is_always_someone_on_the_list(): void
    {
        $task = $this->task($this->first);

        // Fay leaves; Sam is the only one left, so the task is Sam's.
        TaskAssignment::attach($task, $this->second);
        TaskAssignment::detach($task, $this->first);

        $fresh = $task->fresh();

        $this->assertSame($this->second->id, $fresh->user_id);
        $this->assertFalse($fresh->assignees->contains('id', $this->first->id));
    }

    public function test_adding_a_second_person_does_not_take_the_first_one_off(): void
    {
        $task = $this->task($this->first);

        TaskAssignment::attach($task, $this->second);

        $fresh = $task->fresh();

        // Being added is not the same as somebody else leaving.
        $this->assertSame($this->first->id, $fresh->user_id);
        $this->assertEqualsCanonicalizing(
            [$this->first->id, $this->second->id],
            $fresh->assignees->pluck('id')->all()
        );
    }

    public function test_taking_the_last_person_off_leaves_the_last_known_name(): void
    {
        $task = $this->task($this->first);

        TaskAssignment::detach($task, $this->first);

        // Better than a task that says it is assigned to nobody at all.
        $this->assertSame($this->first->id, $task->fresh()->user_id);
    }

    // --- notifications ------------------------------------------------------

    public function test_assigning_someone_tells_them(): void
    {
        $task = $this->task($this->first);
        Notification::fake();

        $this->actingAs($this->author)
            ->postJson("/tasks/{$task->id}/assignees", ['user_id' => $this->second->id])
            ->assertSuccessful();

        Notification::assertSentTo($this->second, TaskAssignedNotification::class);
    }

    public function test_the_handover_is_announced_once_not_twice(): void
    {
        $task = $this->task($this->first);
        Notification::fake();

        // Attaching moves "Assigned to" as well, and that must not read as a
        // second, separate handover.
        TaskAssignment::attach($task, $this->second);
        TaskAssignment::detach($task, $this->first);

        Notification::assertSentToTimes($this->second, TaskAssignedNotification::class, 1);
    }

    // --- what the page says -------------------------------------------------

    public function test_the_task_page_names_everyone_it_is_assigned_to(): void
    {
        $task = $this->task($this->first);
        TaskAssignment::attach($task, $this->second);

        $this->actingAs($this->author)
            ->get("/tasks/{$task->id}")
            ->assertSuccessful()
            ->assertSee('Fay First, Sam Second');
    }

    public function test_the_owner_is_still_whoever_raised_it(): void
    {
        $task = $this->task($this->first);
        TaskAssignment::attach($task, $this->second);
        TaskAssignment::detach($task, $this->first);

        // Assignment moved twice; ownership did not move at all.
        $this->assertSame($this->author->id, $task->fresh()->created_by);
    }
}
