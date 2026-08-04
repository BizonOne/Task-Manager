<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Archive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The board has to answer "whose is this?" and "let me open it" without a
 * hunt for a small icon.
 */
class BoardNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Olive Owner', 'email' => 'olive@example.com', 'password' => bcrypt('secret')]);
        $this->mate = User::create(['name' => 'Mira Mate', 'email' => 'mira@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach($this->mate->id);
    }

    private function task(string $title, User $owner): Task
    {
        return Task::create([
            'user_id' => $owner->id,
            'project_id' => $this->project->id,
            'title' => $title,
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    // --- filtering by who is on a task ------------------------------------

    public function test_the_board_offers_the_people_who_are_actually_on_it(): void
    {
        $task = $this->task('Ship it', $this->owner);
        $task->assignees()->attach($this->mate->id);

        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('All assignees')
            // Raising a task and being given one are different things, and the
            // board used to mix them together with no way to separate them.
            ->assertSee('Raised by me')
            ->assertSee('Assigned to me')
            ->assertSee('Mira Mate');
    }

    public function test_a_card_carries_the_owner_and_everyone_assigned(): void
    {
        $task = $this->task('Ship it', $this->owner);
        $task->assignees()->attach($this->mate->id);

        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('data-owner="'.$this->owner->id.'"', false)
            // Pipe-wrapped, so a filter for "|7|" cannot match user 17. The
            // named assignee is on the list too, so this checks containment
            // rather than the whole attribute.
            ->assertSee('|'.$this->mate->id.'|', false)
            ->assertSee('|'.$this->owner->id.'|', false);
    }

    public function test_a_task_you_are_only_assigned_to_carries_its_real_owner(): void
    {
        $task = $this->task('Miras own work', $this->mate);
        $task->assignees()->attach($this->owner->id);

        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('data-owner="'.$this->mate->id.'"', false)
            ->assertSee('|'.$this->owner->id.'|', false);
    }

    public function test_the_project_board_offers_the_same_filter(): void
    {
        $task = $this->task('Ship it', $this->owner);
        $task->assignees()->attach($this->mate->id);

        // Projects resolve by slug, not id.
        $this->actingAs($this->owner)
            ->get(route('projects.tasks.index', $this->project))
            ->assertSuccessful()
            ->assertSee('All assignees')
            ->assertSee('Mira Mate');
    }

    // --- opening things ----------------------------------------------------

    public function test_a_task_card_opens_the_task(): void
    {
        $task = $this->task('Ship it', $this->owner);

        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('data-open="'.route('tasks.show', $task->id).'"', false);
    }

    public function test_the_task_title_is_a_real_link(): void
    {
        $task = $this->task('Ship it', $this->owner);

        // A link, not a div with a click handler: keyboard and middle-click
        // have to work too.
        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertSee('<a href="'.route('tasks.show', $task->id).'" class="cu-task-title">', false);
    }

    public function test_a_project_card_opens_the_project(): void
    {
        $this->actingAs($this->owner)
            ->get('/projects')
            ->assertSuccessful()
            ->assertSee('data-open="'.route('projects.show', $this->project).'"', false)
            ->assertSee('class="cu-card-name"', false);
    }

    public function test_an_archived_task_is_not_on_the_board_to_be_opened(): void
    {
        $task = $this->task('Done and filed', $this->owner);
        Archive::archive($task);

        $this->actingAs($this->owner)
            ->get('/tasks')
            ->assertSuccessful()
            ->assertDontSee('data-open="'.route('tasks.show', $task->id).'"', false);
    }
}
