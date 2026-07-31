<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\RichText;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RichTextTest extends TestCase
{
    use RefreshDatabase;

    /* ── The sanitiser ── */

    public function test_the_formatting_the_editor_produces_survives(): void
    {
        $html = '<p>A <strong>bold</strong> plan, in <em>three</em> parts:</p>'
            .'<ol><li>One</li><li>Two</li></ol>'
            .'<blockquote>As agreed.</blockquote>'
            .'<pre class="ql-syntax">php artisan migrate</pre>';

        $clean = RichText::clean($html);

        foreach (['<strong>bold</strong>', '<em>three</em>', '<ol>', '<li>One</li>', '<blockquote>', 'php artisan migrate'] as $fragment) {
            $this->assertStringContainsString($fragment, $clean);
        }
    }

    public function test_scripts_and_handlers_are_stripped(): void
    {
        $clean = RichText::clean(
            '<p onclick="steal()">Hi</p><script>alert(1)</script><img src=x onerror="alert(1)">'
        );

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringContainsString('Hi', $clean);
    }

    public function test_only_ordinary_link_protocols_are_allowed(): void
    {
        $clean = RichText::clean('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript', $clean);

        $clean = RichText::clean('<a href="https://example.com">docs</a>');
        $this->assertStringContainsString('href="https://example.com"', $clean);
        // A link that opens a new tab must not be able to reach its opener.
        $this->assertStringContainsString('noreferrer', $clean);
    }

    public function test_an_editor_that_looks_empty_is_stored_as_null(): void
    {
        $this->assertNull(RichText::clean(null));
        $this->assertNull(RichText::clean(''));
        $this->assertTrue(RichText::isEmpty('<p><br></p>'));
    }

    public function test_plain_text_flattens_markup_and_entities(): void
    {
        $this->assertSame(
            'First Second & third',
            RichText::toText('<p>First</p><p>Second &amp; third</p>')
        );

        $this->assertSame('One two...', RichText::toText('<p>One two three</p>', 8));
    }

    /* ── The models ── */

    public function test_every_rich_text_field_is_filtered_on_write(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret')]);
        $payload = '<p>Keep <strong>this</strong></p><script>alert(1)</script>';

        $project = Project::create([
            'user_id' => $user->id, 'name' => 'Apollo',
            'status' => 'in_progress', 'description' => $payload,
        ]);
        $task = Task::create([
            'user_id' => $user->id, 'project_id' => $project->id, 'title' => 'T',
            'priority' => 'low', 'status' => 'to_do', 'description' => $payload,
        ]);
        $note = Note::create(['user_id' => $user->id, 'title' => 'N', 'content' => $payload]);
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $user->id, 'body' => $payload]);

        foreach ([$project->description, $task->description, $note->content, $comment->body] as $stored) {
            $this->assertStringContainsString('<strong>this</strong>', $stored);
            $this->assertStringNotContainsString('script', $stored);
        }

        $this->assertSame('Keep this', $comment->plain_body);
    }

    public function test_a_comment_posted_through_the_discussion_is_filtered(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $task = Task::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'title' => 'T', 'priority' => 'low', 'status' => 'to_do',
        ]);

        $response = $this->actingAs($user)->postJson("/tasks/{$task->id}/comments", [
            'body' => '<p>Ship it</p><img src=x onerror="alert(1)">',
        ])->assertSuccessful();

        // The JSON echoes the stored value, which the page drops straight into
        // the DOM — so what comes back has to be clean too.
        $returned = $response->json('comment.body');
        $this->assertStringContainsString('Ship it', $returned);
        $this->assertStringNotContainsString('onerror', $returned);
        $this->assertStringNotContainsString('onerror', TaskComment::firstOrFail()->body);
    }

    public function test_a_comment_with_no_words_in_it_is_rejected(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $task = Task::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'title' => 'T', 'priority' => 'low', 'status' => 'to_do',
        ]);

        // An untouched editor still posts a paragraph and a line break, which
        // 'required' happily accepts — it just isn't a comment.
        $this->actingAs($user)
            ->postJson("/tasks/{$task->id}/comments", ['body' => '<p><br></p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, TaskComment::count());
    }
}
