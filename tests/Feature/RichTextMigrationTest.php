<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RichTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $this->user->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $this->task = Task::create([
            'user_id' => $this->user->id, 'project_id' => $project->id,
            'title' => 'T', 'priority' => 'low', 'status' => 'to_do',
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_31_170000_convert_plain_text_fields_to_rich_text.php');
    }

    public function test_plain_text_already_in_the_table_keeps_its_shape(): void
    {
        // Written before comments were rich text: raw text, with a newline and
        // a character the browser would read as markup.
        $id = DB::table('task_comments')->insertGetId([
            'task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'body' => "Line one\nLine two <not a tag>",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $body = DB::table('task_comments')->where('id', $id)->value('body');

        $this->assertStringContainsString('<br>', $body, 'The line break has to survive.');
        $this->assertStringContainsString('&lt;not a tag&gt;', $body, 'What was typed must not become markup.');
        $this->assertStringNotContainsString('<not a tag>', $body);
    }

    public function test_existing_markup_is_sanitised_rather_than_escaped(): void
    {
        $id = DB::table('reminders')->insertGetId([
            'user_id' => $this->user->id,
            'title' => 'Old one',
            'description' => '<p>Ring the <strong>bell</strong></p><script>alert(1)</script>',
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $description = DB::table('reminders')->where('id', $id)->value('description');

        $this->assertStringContainsString('<strong>bell</strong>', $description);
        $this->assertStringNotContainsString('script', $description);
    }
}
