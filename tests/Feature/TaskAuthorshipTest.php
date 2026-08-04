<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Raising work for a colleague used to mean losing it: the author was never
 * recorded, so the person who wrote the task could not go back and fix their
 * own wording.
 */
class TaskAuthorshipTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $doer;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::create(['name' => 'Ann Author', 'email' => 'ann@example.com', 'password' => bcrypt('secret')]);
        $this->doer = User::create(['name' => 'Dan Doer', 'email' => 'dan@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Otto Outside', 'email' => 'otto@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->doer->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach([$this->author->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function create(array $extra = []): TestResponse
    {
        return $this->actingAs($this->author)->post('/tasks', array_merge([
            'project_id' => $this->project->id,
            'user_id' => $this->doer->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ], $extra));
    }

    // --- authorship ---------------------------------------------------------

    public function test_creating_a_task_records_who_raised_it(): void
    {
        $this->create()->assertRedirect();

        $task = Task::latest('id')->first();

        $this->assertSame($this->author->id, $task->created_by);
        // Raised by one person, for another.
        $this->assertSame($this->doer->id, $task->user_id);
    }

    public function test_the_author_can_edit_a_task_they_raised_for_someone_else(): void
    {
        $this->create()->assertRedirect();
        $task = Task::latest('id')->first();

        // This was a 403 before: the author had no claim on their own words.
        $this->actingAs($this->author)
            ->get("/tasks/{$task->id}/edit")
            ->assertSuccessful();

        $this->actingAs($this->author)->put("/tasks/{$task->id}", [
            'title' => 'Ship the invoice run (rewritten)',
            'priority' => 'high',
            'status' => 'to_do',
            'user_id' => $this->doer->id,
        ])->assertRedirect();

        $this->assertSame('Ship the invoice run (rewritten)', $task->fresh()->title);
    }

    public function test_the_person_it_was_raised_for_can_still_edit_it(): void
    {
        $this->create()->assertRedirect();
        $task = Task::latest('id')->first();

        $this->actingAs($this->doer)->get("/tasks/{$task->id}/edit")->assertSuccessful();
    }

    public function test_authorship_cannot_be_claimed_through_the_form(): void
    {
        // created_by is not fillable, and this is why.
        $this->create(['created_by' => $this->outsider->id])->assertRedirect();

        $this->assertSame($this->author->id, Task::latest('id')->first()->created_by);
    }

    public function test_someone_with_no_part_in_it_still_cannot_edit(): void
    {
        $this->create()->assertRedirect();
        $task = Task::latest('id')->first();

        $this->actingAs($this->outsider)->get("/tasks/{$task->id}/edit")->assertForbidden();
    }

    public function test_a_task_you_raised_counts_as_yours_in_reports(): void
    {
        $this->create()->assertRedirect();

        $titles = Task::query()->involving($this->author)->pluck('title');

        $this->assertTrue($titles->contains('Ship the invoice run'));
    }

    // --- attaching at creation ---------------------------------------------

    public function test_a_file_can_come_with_the_task_rather_than_after_it(): void
    {
        Storage::fake(Uploads::disk());

        $this->create([
            'attachments' => [
                UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('budget.xlsx', 12, 'application/vnd.ms-excel'),
            ],
        ])->assertRedirect();

        $task = Task::latest('id')->first();

        $this->assertSame(2, $task->files()->count());
        // Same convention as the Files page: the display name drops the
        // extension, original_name keeps it.
        $this->assertEqualsCanonicalizing(
            ['spec.pdf', 'budget.xlsx'],
            $task->files()->pluck('original_name')->all()
        );
    }

    public function test_the_attachment_belongs_to_whoever_uploaded_it(): void
    {
        Storage::fake(Uploads::disk());

        $this->create(['attachments' => [UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf')]])
            ->assertRedirect();

        $file = Task::latest('id')->first()->files()->first();

        $this->assertSame($this->author->id, $file->user_id);
    }

    public function test_a_rejected_file_does_not_leave_a_task_behind(): void
    {
        Storage::fake(Uploads::disk());

        $this->create(['attachments' => [UploadedFile::fake()->create('payload.exe', 20)]])
            ->assertSessionHasErrors('attachments.0');

        // Validation runs before anything is written.
        $this->assertSame(0, Task::count());
    }

    public function test_creating_a_task_without_a_file_still_works(): void
    {
        $this->create()->assertRedirect();

        $this->assertSame(1, Task::count());
        $this->assertSame(0, Task::first()->files()->count());
    }
}
