<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskOwnerAndFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $mate;

    private User $outsider;

    private Project $project;

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
    }

    /* ── Task owner ── */

    public function test_the_chosen_person_becomes_the_task_owner(): void
    {
        // The creator used to be forced in regardless of the form, so every
        // task had to be reassigned by hand afterwards.
        $this->actingAs($this->owner)->post('/tasks', [
            'project_id' => $this->project->id,
            'user_id' => $this->mate->id,
            'title' => 'Draft the brief',
            'priority' => 'medium',
            'status' => 'to_do',
        ])->assertRedirect();

        $task = Task::where('title', 'Draft the brief')->firstOrFail();
        $this->assertSame($this->mate->id, $task->user_id, 'The person picked in the form must own the task.');
    }

    public function test_the_owner_must_be_able_to_reach_the_project(): void
    {
        $this->actingAs($this->owner)->post('/tasks', [
            'project_id' => $this->project->id,
            'user_id' => $this->outsider->id,
            'title' => 'Nope',
            'priority' => 'medium',
            'status' => 'to_do',
        ])->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('tasks', ['title' => 'Nope']);
    }

    public function test_reassigning_on_edit_is_held_to_the_same_rule(): void
    {
        $task = Task::create([
            'user_id' => $this->owner->id, 'project_id' => $this->project->id,
            'title' => 'Existing', 'priority' => 'low', 'status' => 'to_do',
        ]);

        $payload = ['title' => 'Existing', 'priority' => 'low', 'status' => 'to_do'];

        $this->actingAs($this->owner)
            ->put("/tasks/{$task->id}", $payload + ['user_id' => $this->mate->id])
            ->assertRedirect();
        $this->assertSame($this->mate->id, $task->fresh()->user_id);

        $this->actingAs($this->owner)
            ->put("/tasks/{$task->id}", $payload + ['user_id' => $this->outsider->id])
            ->assertSessionHasErrors('user_id');
        $this->assertSame($this->mate->id, $task->fresh()->user_id);
    }

    public function test_the_create_form_only_offers_projects_you_can_use(): void
    {
        Project::create(['user_id' => $this->outsider->id, 'name' => 'Secret Skunkworks', 'status' => 'in_progress']);

        $this->actingAs($this->mate)->get('/tasks')
            ->assertSuccessful()
            ->assertSee('Apollo')
            ->assertDontSee('Secret Skunkworks');
    }

    /* ── Files ── */

    public function test_uploading_then_downloading_a_file_works(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Spec',
            'type' => 'docs',
            'file' => UploadedFile::fake()->create('spec sheet.pdf', 12, 'application/pdf'),
        ])->assertRedirect();

        $file = File::where('name', 'Spec')->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('spec sheet.pdf', $file->original_name);

        // Downloads go through the app; the old public URL 404'd because the
        // storage symlink is never created on deploy.
        $response = $this->actingAs($this->owner)->get(route('files.download', $file));
        $response->assertSuccessful();
        $this->assertStringContainsString('spec sheet.pdf', $response->headers->get('content-disposition'));
    }

    public function test_a_file_is_not_downloadable_by_anyone_else(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Private', 'type' => 'docs',
            'file' => UploadedFile::fake()->create('private.pdf', 8, 'application/pdf'),
        ]);
        $file = File::where('name', 'Private')->firstOrFail();

        $this->actingAs($this->outsider)->get(route('files.download', $file))->assertForbidden();
    }

    public function test_a_missing_file_reports_404_rather_than_a_500(): void
    {
        Storage::fake('local');

        // A row can outlive its file — the container filesystem is ephemeral.
        $file = File::create([
            'user_id' => $this->owner->id,
            'name' => 'Gone',
            'path' => 'uploads/vanished.pdf',
            'type' => 'docs',
        ]);

        $this->actingAs($this->owner)->get(route('files.download', $file))->assertNotFound();
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Huge', 'type' => 'docs',
            'file' => UploadedFile::fake()->create('huge.pdf', 30720, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    public function test_avatars_are_served_by_the_app(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->put('/profile', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect();

        $this->assertNotNull($this->owner->fresh()->avatar);

        $this->actingAs($this->owner)
            ->get(route('avatar.show', $this->owner))
            ->assertSuccessful();
    }

    public function test_an_avatar_that_was_never_set_is_a_404(): void
    {
        $this->actingAs($this->owner)
            ->get(route('avatar.show', $this->mate))
            ->assertNotFound();
    }
}
