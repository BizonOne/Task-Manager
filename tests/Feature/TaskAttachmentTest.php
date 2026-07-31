<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $mate;

    private User $outsider;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);
        Storage::fake('local');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->mate = User::create(['name' => 'Mate', 'email' => 'mate@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Outsider', 'email' => 'out@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $this->project->users()->attach($this->mate->id, ['role' => 'member']);

        $this->task = Task::create([
            'user_id' => $this->owner->id, 'project_id' => $this->project->id,
            'title' => 'Ship it', 'priority' => 'medium', 'status' => 'to_do',
        ]);
    }

    /* ── Attaching to a task ── */

    public function test_a_file_can_be_attached_to_a_task(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('spec sheet.pdf', 12, 'application/pdf')],
        ])->assertSuccessful();

        $file = File::firstOrFail();
        $this->assertSame($this->task->id, $file->task_id);
        $this->assertNull($file->task_comment_id, 'Uploaded from the task, not from a comment.');
        $this->assertSame('spec sheet.pdf', $file->original_name);
        Storage::disk('local')->assertExists($file->path);

        // The page renders the new row from this payload.
        $response->assertJsonPath('files.0.name', 'spec sheet.pdf');
        $this->assertNotNull($response->json('files.0.url'));
    }

    public function test_several_files_can_go_up_at_once(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [
                UploadedFile::fake()->create('one.pdf', 4, 'application/pdf'),
                UploadedFile::fake()->image('two.png'),
            ],
        ])->assertSuccessful();

        $this->assertSame(2, $this->task->files()->count());
        $this->assertSame('image', File::where('original_name', 'two.png')->value('type'));
    }

    public function test_someone_outside_the_project_cannot_attach(): void
    {
        $this->actingAs($this->outsider)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('nope.pdf', 4, 'application/pdf')],
        ])->assertForbidden();

        $this->assertSame(0, File::count());
    }

    public function test_the_size_cap_and_type_list_still_apply(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('huge.pdf', 30720, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('files.0');

        $this->assertSame(0, File::count());
    }

    /* ── Who can see an attachment ── */

    public function test_everyone_on_the_task_can_download_an_attachment(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('brief.pdf', 6, 'application/pdf')],
        ]);
        $file = File::firstOrFail();

        // A teammate did not upload it, but the whole point of attaching it to
        // the task is that the team can open it.
        $this->actingAs($this->mate)->get(route('files.download', $file))->assertSuccessful();
        $this->actingAs($this->outsider)->get(route('files.download', $file))->assertForbidden();
    }

    public function test_a_private_upload_stays_private(): void
    {
        // Uploaded from the Files page: hung on no task, so it is nobody
        // else's business.
        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Payslip', 'type' => 'docs',
            'file' => UploadedFile::fake()->create('payslip.pdf', 4, 'application/pdf'),
        ]);
        $file = File::firstOrFail();

        $this->assertNull($file->task_id);
        $this->actingAs($this->mate)->get(route('files.download', $file))->assertForbidden();
    }

    public function test_a_teammate_cannot_delete_someone_elses_attachment(): void
    {
        $this->actingAs($this->mate)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('mine.pdf', 4, 'application/pdf')],
        ]);
        $file = File::firstOrFail();

        // A plain member did not upload it and does not manage the task.
        $other = User::create(['name' => 'Third', 'email' => 'third@example.com', 'password' => bcrypt('secret')]);
        $this->project->users()->attach($other->id, ['role' => 'member']);
        $this->actingAs($other)->deleteJson(route('files.destroy', $file))->assertForbidden();

        // The task owner manages the task, so they can clear it.
        $this->actingAs($this->owner)->deleteJson(route('files.destroy', $file))->assertSuccessful();
        $this->assertSame(0, File::count());
        Storage::disk('local')->assertMissing($file->path);
    }

    /* ── Attaching in the discussion ── */

    public function test_a_comment_can_carry_files(): void
    {
        $response = $this->actingAs($this->mate)->post(route('tasks.comments.store', $this->task), [
            'body' => '<p>Here is the draft</p>',
            'attachments' => [UploadedFile::fake()->create('draft.docx', 9)],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $comment = TaskComment::firstOrFail();
        $file = File::firstOrFail();

        $this->assertSame($comment->id, $file->task_comment_id);
        $this->assertSame($this->task->id, $file->task_id, 'A comment attachment belongs to the task as well.');
        $response->assertJsonPath('comment.files.0.name', 'draft.docx');

        // It shows in the task's attachment list, not only in the thread.
        $this->assertSame(1, $this->task->files()->count());
    }

    public function test_a_file_on_its_own_is_a_valid_comment(): void
    {
        $this->actingAs($this->mate)->post(route('tasks.comments.store', $this->task), [
            'body' => '',
            'attachments' => [UploadedFile::fake()->image('screenshot.png')],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame(1, TaskComment::count());
        $this->assertSame(1, File::count());
    }

    public function test_an_empty_comment_with_nothing_attached_is_still_rejected(): void
    {
        $this->actingAs($this->mate)->postJson(route('tasks.comments.store', $this->task), [
            'body' => '<p><br></p>',
        ])->assertStatus(422);

        $this->assertSame(0, TaskComment::count());
    }

    public function test_deleting_a_comment_takes_its_attachments_with_it(): void
    {
        $this->actingAs($this->mate)->post(route('tasks.comments.store', $this->task), [
            'body' => '<p>Draft</p>',
            'attachments' => [UploadedFile::fake()->create('draft.docx', 9)],
        ], ['Accept' => 'application/json']);

        $comment = TaskComment::firstOrFail();
        $file = File::firstOrFail();
        $path = $file->path;

        $this->actingAs($this->mate)->deleteJson(route('comments.destroy', $comment))
            ->assertSuccessful()
            ->assertJsonPath('removed_file_ids.0', $file->id);

        $this->assertSame(0, File::count(), 'The row cascades with the comment.');
        Storage::disk('local')->assertMissing($path);
    }

    /* ── The page ── */

    public function test_a_pdf_preview_is_not_framed_over_http(): void
    {
        // The edge sends X-Frame-Options: deny for the whole site, so an
        // <iframe> pointing back at our own download route is refused by the
        // browser. The page loads the bytes and frames a blob instead.
        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Brief', 'type' => 'docs',
            'file' => UploadedFile::fake()->create('brief.pdf', 6, 'application/pdf'),
        ]);
        $file = File::firstOrFail();

        $html = $this->actingAs($this->owner)->get(route('files.show', $file))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('id="pdf-preview"', $html);
        $this->assertStringNotContainsString(
            '<iframe src="'.route('files.download', [$file, 'inline' => 1]),
            $html,
            'Framing the download URL over HTTP is exactly what the edge refuses.'
        );
    }

    public function test_the_file_page_reports_the_recorded_size(): void
    {
        $this->actingAs($this->owner)->post('/files', [
            'name' => 'Brief', 'type' => 'docs',
            'file' => UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'),
        ]);
        $file = File::firstOrFail();

        // It used to ask a hardcoded disk, so anything stored elsewhere — which
        // on production is everything — read "Unknown".
        $this->actingAs($this->owner)->get(route('files.show', $file))
            ->assertSuccessful()
            ->assertSee($file->readable_size)
            ->assertDontSee('Unknown');
    }

    public function test_the_task_page_lists_its_attachments(): void
    {
        $this->actingAs($this->owner)->postJson(route('tasks.files.attach', $this->task), [
            'files' => [UploadedFile::fake()->create('agenda.pdf', 5, 'application/pdf')],
        ]);

        $this->actingAs($this->mate)->get(route('tasks.show', $this->task))
            ->assertSuccessful()
            ->assertSee('Attachments')
            ->assertSee('agenda.pdf');
    }
}
