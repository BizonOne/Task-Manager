<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectField;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every team keeps something on a task that no other team needs. A project
 * says what it wants to record; its tasks answer; the board can be read and
 * filtered by the answer.
 *
 * The line that matters throughout: a field belongs to one project. Statuses
 * are shared by every board in the app, so adding a column for one team puts
 * it on everybody's screen — a field must never do that.
 */
class ProjectFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->manager = User::create(['name' => 'Mara Manager', 'email' => 'mara@example.com', 'password' => bcrypt('secret')]);
        $this->member = User::create(['name' => 'Mel Member', 'email' => 'mel@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->manager->id, 'name' => 'Onboarding', 'status' => 'in_progress']);
        $this->project->users()->attach($this->member->id);
    }

    private function field(array $overrides = []): ProjectField
    {
        return $this->project->fields()->create(array_merge([
            'name' => 'Acquirer',
            'type' => ProjectField::TYPE_SELECT,
            'options' => ['XSELL', 'MADFIN', 'GURUPAY'],
            'show_on_card' => true,
        ], $overrides));
    }

    private function task(?User $owner = null): Task
    {
        $this->actingAs($owner ?? $this->manager);

        return Task::create([
            'user_id' => ($owner ?? $this->manager)->id,
            'project_id' => $this->project->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => 'to_do',
        ]);
    }

    // --- who may define them -------------------------------------------------

    public function test_whoever_manages_the_project_can_add_a_field(): void
    {
        $this->actingAs($this->manager)
            ->post(route('projects.fields.store', $this->project), [
                'name' => 'Acquirer',
                'type' => ProjectField::TYPE_SELECT,
                'options' => "XSELL\nMADFIN\n\nGURUPAY",
                'show_on_card' => '1',
            ])
            ->assertRedirect();

        $field = $this->project->fields()->first();

        $this->assertNotNull($field);
        $this->assertSame('acquirer', $field->key);
        // The blank line between them is not a choice.
        $this->assertSame(['XSELL', 'MADFIN', 'GURUPAY'], $field->choices());
    }

    public function test_a_member_who_does_not_manage_the_project_cannot(): void
    {
        // Otherwise anybody on any project could rewrite what everyone else is
        // recording on their work.
        $this->actingAs($this->member)
            ->post(route('projects.fields.store', $this->project), [
                'name' => 'Acquirer',
                'type' => ProjectField::TYPE_TEXT,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->project->fields()->count());
    }

    public function test_a_pick_one_field_needs_something_to_pick(): void
    {
        $this->actingAs($this->manager)
            ->post(route('projects.fields.store', $this->project), [
                'name' => 'Acquirer',
                'type' => ProjectField::TYPE_SELECT,
                'options' => '',
            ])
            ->assertSessionHasErrors('options');
    }

    public function test_free_text_needs_no_choices(): void
    {
        $this->actingAs($this->manager)
            ->post(route('projects.fields.store', $this->project), [
                'name' => 'Contract reference',
                'type' => ProjectField::TYPE_TEXT,
            ])
            ->assertRedirect();

        $this->assertSame(ProjectField::TYPE_TEXT, $this->project->fields()->first()->type);
    }

    public function test_a_field_belongs_to_its_own_project_and_no_other(): void
    {
        $this->field();

        $other = Project::create(['user_id' => $this->manager->id, 'name' => 'Something else', 'status' => 'in_progress']);

        // The whole reason this is not a status: one team's column does not
        // land on everybody's board.
        $this->assertSame(0, $other->fields()->count());

        $this->actingAs($this->manager)
            ->get(route('projects.tasks.index', $other))
            ->assertSuccessful()
            ->assertDontSee('All acquirer');
    }

    // --- answering them ------------------------------------------------------

    public function test_a_task_created_from_the_board_carries_its_answer(): void
    {
        $field = $this->field();

        $this->actingAs($this->manager)->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => 'to_do',
            'fields' => [$field->id => 'GURUPAY'],
        ])->assertRedirect();

        $task = Task::where('title', 'Onboard Acme')->first();

        $this->assertSame(['GURUPAY'], $task->fieldAnswers()->first()['value']);
    }

    public function test_a_choice_the_field_does_not_offer_is_not_an_answer(): void
    {
        $field = $this->field();
        $task = $this->task();

        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'fields' => [$field->id => 'SOMETHING ELSE'],
        ])->assertRedirect();

        // A dropdown that can be talked into holding anything is a text box
        // with extra steps.
        $this->assertSame([], $task->fresh()->fieldAnswers()->first()['value']);
    }

    public function test_picking_several_is_allowed_when_the_field_says_so(): void
    {
        $field = $this->field(['type' => ProjectField::TYPE_MULTI]);
        $task = $this->task();

        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'fields' => [$field->id => ['XSELL', 'GURUPAY']],
        ])->assertRedirect();

        $this->assertSame(['XSELL', 'GURUPAY'], $task->fresh()->fieldAnswers()->first()['value']);
    }

    public function test_a_pick_one_field_holds_one_thing_however_many_arrive(): void
    {
        $field = $this->field();
        $task = $this->task();

        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'fields' => [$field->id => ['XSELL', 'GURUPAY']],
        ])->assertRedirect();

        $this->assertSame(['XSELL'], $task->fresh()->fieldAnswers()->first()['value']);
    }

    public function test_clearing_an_answer_removes_it(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['XSELL']]);

        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'fields' => [$field->id => ''],
        ])->assertRedirect();

        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_a_form_that_never_carried_the_fields_leaves_them_alone(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['XSELL']]);

        // Dragging a card across the board, or any older form: silence is not
        // an instruction to wipe them.
        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
        ])->assertRedirect();

        $this->assertSame(['XSELL'], $task->fresh()->fieldAnswers()->first()['value']);
    }

    // --- what changes underneath ---------------------------------------------

    public function test_renaming_a_field_keeps_every_answer(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['XSELL']]);

        $this->actingAs($this->manager)->put(route('projects.fields.update', $field), [
            'name' => 'Acquiring bank',
            'type' => ProjectField::TYPE_SELECT,
            'options' => "XSELL\nMADFIN",
        ])->assertRedirect();

        $answer = $task->fresh()->fieldAnswers()->first();

        $this->assertSame('Acquiring bank', $answer['field']->name);
        $this->assertSame('acquirer', $answer['field']->key);
        $this->assertSame(['XSELL'], $answer['value']);
    }

    public function test_removing_a_field_takes_its_answers_with_it(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['XSELL']]);

        $this->actingAs($this->manager)
            ->delete(route('projects.fields.destroy', $field))
            ->assertRedirect();

        // An answer to a question nobody is asking any more is not worth
        // keeping.
        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_moving_a_task_to_another_project_drops_answers_that_no_longer_apply(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['XSELL']]);

        $elsewhere = Project::create(['user_id' => $this->manager->id, 'name' => 'Elsewhere', 'status' => 'in_progress']);

        $this->actingAs($this->manager)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'priority' => 'medium',
            'status' => 'to_do',
            'project_id' => $elsewhere->id,
        ])->assertRedirect();

        $this->assertSame(0, $task->fieldValues()->count());
    }

    // --- reading them --------------------------------------------------------

    public function test_the_task_page_shows_the_answer(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['GURUPAY']]);

        $this->actingAs($this->manager)
            ->get(route('tasks.show', $task))
            ->assertSuccessful()
            ->assertSee('Acquirer')
            ->assertSee('GURUPAY');
    }

    public function test_the_board_offers_a_filter_and_the_cards_can_answer_it(): void
    {
        $field = $this->field();
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['GURUPAY']]);

        $this->actingAs($this->manager)
            ->get(route('projects.tasks.index', $this->project))
            ->assertSuccessful()
            ->assertSee('data-field-key="acquirer"', false)
            // Pipe-wrapped both sides, so "GURU" cannot match "GURUPAY".
            ->assertSee('|acquirer::GURUPAY|', false);
    }

    public function test_a_field_kept_off_the_card_is_still_on_the_task(): void
    {
        $field = $this->field(['show_on_card' => false]);
        $task = $this->task();
        $task->fieldValues()->create(['project_field_id' => $field->id, 'value' => ['GURUPAY']]);

        $this->assertTrue($task->fieldChips()->isEmpty());
        $this->assertSame(['GURUPAY'], $task->fieldAnswers()->first()['value']);
    }

    public function test_the_project_settings_page_is_where_they_are_managed(): void
    {
        $this->field();

        $this->actingAs($this->manager)
            ->get(route('projects.edit', $this->project))
            ->assertSuccessful()
            ->assertSee('Task fields')
            ->assertSee('XSELL');
    }

    public function test_the_task_form_asks_the_project_its_own_questions(): void
    {
        $field = $this->field();
        $task = $this->task();

        $this->actingAs($this->manager)
            ->get(route('tasks.edit', $task))
            ->assertSuccessful()
            ->assertSee('name="fields['.$field->id.']"', false)
            ->assertSee('data-project-fields="'.$this->project->id.'"', false);
    }

    public function test_two_fields_keep_their_order(): void
    {
        $this->field(['name' => 'Second', 'sort_order' => 2]);
        $this->field(['name' => 'First', 'sort_order' => 1]);

        $this->assertSame(['First', 'Second'], $this->project->fields()->pluck('name')->all());
    }
}
