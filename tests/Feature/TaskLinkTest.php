<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $mate;

    private User $outsider;

    private Project $project;

    private Task $alpha;

    private Task $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->mate = User::create(['name' => 'Mate', 'email' => 'mate@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Outsider', 'email' => 'out@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $this->project->users()->attach($this->mate->id, ['role' => 'member']);

        $this->alpha = $this->task('Ship the invoice run');
        $this->beta = $this->task('Fix the currency rounding');
    }

    private function task(string $title, ?Project $project = null, ?User $owner = null): Task
    {
        return Task::create([
            'user_id' => ($owner ?? $this->owner)->id,
            'project_id' => ($project ?? $this->project)->id,
            'title' => $title, 'priority' => 'medium', 'status' => 'to_do',
        ]);
    }

    /* ── Creating a link ── */

    public function test_a_link_reads_both_ways_from_one_row(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->beta), [
            'type' => 'blocks',
            'linked_task_id' => $this->alpha->id,
        ])->assertSuccessful()->assertJsonPath('link.label', 'blocks');

        // One row, not a mirrored pair that can drift apart.
        $this->assertSame(1, TaskLink::count());

        $link = TaskLink::firstOrFail();
        $this->assertSame('blocks', $link->labelFor($this->beta));
        $this->assertSame('is blocked by', $link->labelFor($this->alpha));
    }

    public function test_the_inverse_wording_stores_the_tasks_the_other_way_round(): void
    {
        // "This task is blocked by Alpha" is the same fact as "Alpha blocks this".
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->beta), [
            'type' => 'blocks_inverse',
            'linked_task_id' => $this->alpha->id,
        ])->assertSuccessful()->assertJsonPath('link.label', 'is blocked by');

        $link = TaskLink::firstOrFail();
        $this->assertSame($this->alpha->id, $link->task_id, 'Alpha is the blocker.');
        $this->assertSame($this->beta->id, $link->linked_task_id);
    }

    public function test_a_task_cannot_be_linked_to_itself(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->alpha), [
            'type' => 'relates_to',
            'linked_task_id' => $this->alpha->id,
        ])->assertStatus(422)->assertJsonValidationErrors('linked_task_id');

        $this->assertSame(0, TaskLink::count());
    }

    public function test_the_same_relation_cannot_be_added_twice_in_either_direction(): void
    {
        $payload = ['type' => 'blocks', 'linked_task_id' => $this->beta->id];
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->alpha), $payload)->assertSuccessful();
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->alpha), $payload)->assertStatus(422);

        // And not from the other end either, which is the same fact reversed.
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->beta), [
            'type' => 'blocks_inverse', 'linked_task_id' => $this->alpha->id,
        ])->assertStatus(422);

        $this->assertSame(1, TaskLink::count());
    }

    /* ── Who may link what ── */

    public function test_you_cannot_link_to_a_task_you_cannot_see(): void
    {
        $secret = $this->task('Secret work', Project::create([
            'user_id' => $this->outsider->id, 'name' => 'Skunkworks', 'status' => 'in_progress',
        ]), $this->outsider);

        // Linking would print the other task's title on a page you can reach.
        $this->actingAs($this->mate)->postJson(route('tasks.links.store', $this->alpha), [
            'type' => 'relates_to', 'linked_task_id' => $secret->id,
        ])->assertForbidden();

        $this->assertSame(0, TaskLink::count());
    }

    public function test_the_picker_only_offers_tasks_you_can_reach(): void
    {
        $this->task('Secret work', Project::create([
            'user_id' => $this->outsider->id, 'name' => 'Skunkworks', 'status' => 'in_progress',
        ]), $this->outsider);

        $titles = collect($this->actingAs($this->mate)
            ->getJson(route('tasks.links.search', $this->alpha))
            ->assertSuccessful()
            ->json('tasks'))
            ->pluck('title');

        $this->assertTrue($titles->contains('Fix the currency rounding'));
        $this->assertFalse($titles->contains('Secret work'));
        $this->assertFalse($titles->contains('Ship the invoice run'), 'The task itself is not a candidate.');
    }

    public function test_the_picker_finds_a_task_by_its_number(): void
    {
        $titles = collect($this->actingAs($this->owner)
            ->getJson(route('tasks.links.search', $this->alpha).'?q='.$this->beta->id)
            ->json('tasks'))
            ->pluck('title');

        $this->assertTrue($titles->contains('Fix the currency rounding'));
    }

    public function test_a_link_to_an_unreachable_task_is_not_shown(): void
    {
        // A second project of the owner's that the teammate has no part in.
        $private = Project::create([
            'user_id' => $this->owner->id, 'name' => 'Board pack', 'status' => 'in_progress',
        ]);
        $secret = $this->task('Redundancy plan', $private);

        // The owner can see both ends and makes the link; the teammate can
        // only see one of them.
        TaskLink::create([
            'task_id' => $this->alpha->id, 'linked_task_id' => $secret->id,
            'type' => 'relates_to', 'created_by' => $this->owner->id,
        ]);

        $this->assertTrue($this->alpha->groupedLinks($this->owner)->isNotEmpty());
        $this->assertTrue(
            $this->alpha->fresh()->groupedLinks($this->mate)->isEmpty(),
            'A relation must not leak the title of work you have no part in.'
        );
    }

    /* ── Removing ── */

    public function test_either_end_can_cut_the_link(): void
    {
        $link = TaskLink::create([
            'task_id' => $this->alpha->id, 'linked_task_id' => $this->beta->id,
            'type' => 'blocks', 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->outsider)->deleteJson(route('tasks.links.destroy', $link))->assertForbidden();
        $this->actingAs($this->owner)->deleteJson(route('tasks.links.destroy', $link))->assertSuccessful();
        $this->assertSame(0, TaskLink::count());
    }

    public function test_deleting_a_task_takes_its_links_with_it(): void
    {
        TaskLink::create([
            'task_id' => $this->alpha->id, 'linked_task_id' => $this->beta->id,
            'type' => 'blocks', 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->delete(route('tasks.destroy', $this->beta));

        $this->assertSame(0, TaskLink::count(), 'A link to a task that no longer exists is not a link.');
    }

    /* ── History ── */

    public function test_both_histories_record_the_link_in_their_own_words(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->alpha), [
            'type' => 'blocks', 'linked_task_id' => $this->beta->id,
        ])->assertSuccessful();

        $onAlpha = TaskActivity::where('task_id', $this->alpha->id)
            ->where('event', TaskActivity::EVENT_LINKED)->firstOrFail();
        $onBeta = TaskActivity::where('task_id', $this->beta->id)
            ->where('event', TaskActivity::EVENT_LINKED)->firstOrFail();

        $this->assertStringContainsString('blocks', $onAlpha->description);
        $this->assertStringContainsString('Fix the currency rounding', $onAlpha->description);
        $this->assertStringContainsString('is blocked by', $onBeta->description);
        $this->assertStringContainsString('Ship the invoice run', $onBeta->description);
    }

    /* ── The page ── */

    public function test_the_task_page_groups_links_by_their_wording(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.links.store', $this->alpha), [
            'type' => 'blocks', 'linked_task_id' => $this->beta->id,
        ]);

        $this->actingAs($this->mate)->get(route('tasks.show', $this->alpha))
            ->assertSuccessful()
            ->assertSee('Linked tasks')
            ->assertSee('blocks')
            ->assertSee('Fix the currency rounding');

        $this->actingAs($this->mate)->get(route('tasks.show', $this->beta))
            ->assertSuccessful()
            ->assertSee('is blocked by')
            ->assertSee('Ship the invoice run');
    }
}
