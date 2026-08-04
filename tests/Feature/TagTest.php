<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\Tags;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project's fields are a question with a fixed set of answers. Tags are the
 * opposite: somebody needs to write "urgent-legal" on four tasks across three
 * projects right now, and inventing a field for it would take longer than the
 * work itself.
 */
class TagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Tara Tagger', 'email' => 'tara@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->user->id, 'name' => 'Onboarding', 'status' => 'in_progress']);
    }

    private function task(): Task
    {
        $this->actingAs($this->user);

        return Task::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => 'to_do',
        ]);
    }

    // --- writing them --------------------------------------------------------

    public function test_tags_are_made_as_they_are_typed(): void
    {
        $this->actingAs($this->user)->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => 'to_do',
            'tags' => 'urgent, compliance',
        ])->assertRedirect();

        $task = Task::where('title', 'Onboard Acme')->first();

        // Nobody should have to create a tag before using it.
        $this->assertEqualsCanonicalizing(['compliance', 'urgent'], $task->tags->pluck('name')->all());
    }

    public function test_the_same_tag_written_differently_is_the_same_tag(): void
    {
        $first = $this->task();
        $second = $this->task();

        Tags::apply($first, 'Urgent Legal');
        Tags::apply($second, 'urgent-legal');

        // Two tags that look alike on a board are worse than none: a filter
        // for one silently hides the other's tasks.
        $this->assertSame(1, Tag::count());
        $this->assertSame($first->tags->first()->id, $second->fresh()->tags->first()->id);
    }

    public function test_a_tag_may_have_a_space_in_it(): void
    {
        $task = $this->task();

        Tags::apply($task, 'legal hold, q3');

        // Split on commas, not spaces — otherwise "legal hold" becomes two
        // tags that mean nothing apart.
        $this->assertEqualsCanonicalizing(['legal hold', 'q3'], $task->fresh()->tags->pluck('name')->all());
    }

    public function test_blank_entries_and_repeats_are_dropped(): void
    {
        $task = $this->task();

        Tags::apply($task, 'urgent, , urgent,   ,URGENT');

        $this->assertSame(['urgent'], $task->fresh()->tags->pluck('name')->all());
    }

    public function test_there_is_a_limit_to_how_many_one_task_can_wear(): void
    {
        $task = $this->task();

        Tags::apply($task, implode(',', array_map(fn ($i) => 'tag'.$i, range(1, 20))));

        // A task wearing twenty tags has been filed by somebody who meant to
        // write a description.
        $this->assertSame(Tags::MAX, $task->fresh()->tags->count());
    }

    public function test_editing_a_task_replaces_its_tags(): void
    {
        $task = $this->task();
        Tags::apply($task, 'urgent');

        $this->actingAs($this->user)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'tags' => 'compliance',
        ])->assertRedirect();

        $this->assertSame(['compliance'], $task->fresh()->tags->pluck('name')->all());
    }

    public function test_an_empty_box_clears_them_but_no_box_at_all_does_not(): void
    {
        $task = $this->task();
        Tags::apply($task, 'urgent');

        // Dragging a card across the board sends no tags; that is not the same
        // as somebody emptying the box.
        Tags::apply($task, null);
        $this->assertSame(1, $task->fresh()->tags->count());

        Tags::apply($task, '');
        $this->assertSame(0, $task->fresh()->tags->count());
    }

    // --- reading them --------------------------------------------------------

    public function test_the_task_page_shows_them(): void
    {
        $task = $this->task();
        Tags::apply($task, 'compliance');

        $this->actingAs($this->user)
            ->get(route('tasks.show', $task))
            ->assertSuccessful()
            ->assertSee('#compliance');
    }

    public function test_the_board_offers_a_tag_filter_the_cards_can_answer(): void
    {
        $task = $this->task();
        Tags::apply($task, 'legal hold');

        $this->actingAs($this->user)
            ->get(route('tasks.index'))
            ->assertSuccessful()
            ->assertSee('All tags')
            // Pipe-wrapped, so a filter for "legal" cannot match "legal-hold".
            ->assertSee('data-tags="|legal-hold|"', false);
    }

    public function test_a_tag_crosses_projects(): void
    {
        $here = $this->task();

        $elsewhere = Project::create(['user_id' => $this->user->id, 'name' => 'Elsewhere', 'status' => 'in_progress']);
        $there = Task::create([
            'user_id' => $this->user->id,
            'project_id' => $elsewhere->id,
            'title' => 'Something else',
            'priority' => 'low',
            'status' => 'to_do',
        ]);

        Tags::apply($here, 'compliance');
        Tags::apply($there, 'compliance');

        // The point of a tag rather than a project field: one word, everywhere
        // it applies.
        $this->assertSame(2, Tag::where('slug', 'compliance')->first()->tasks()->count());
    }

    public function test_the_edit_form_puts_them_back_in_the_box(): void
    {
        $task = $this->task();
        Tags::apply($task, 'urgent, compliance');

        $this->actingAs($this->user)
            ->get(route('tasks.edit', $task))
            ->assertSuccessful()
            ->assertSee('value="compliance, urgent"', false);
    }
}
