<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Dates;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "2 days ago" or "Aug 03, 2026 23:59" — each person picks how times are
 * written for them, and whichever they pick, the other is on hover.
 */
class DateDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Pasha', 'email' => 'pasha@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $this->user->id, 'name' => 'Proj', 'status' => 'in_progress']);
        $this->task = Task::create([
            'user_id' => $this->user->id, 'project_id' => $project->id,
            'title' => 'Do it', 'priority' => 'medium', 'status' => 'to_do',
        ]);
    }

    private function commentTwoDaysAgo(): TaskComment
    {
        $comment = TaskComment::create(['task_id' => $this->task->id, 'user_id' => $this->user->id, 'body' => 'Hello']);
        $comment->forceFill(['created_at' => now()->subDays(2)])->save();

        return $comment;
    }

    public function test_relative_is_the_default_with_the_exact_time_on_hover(): void
    {
        $comment = $this->commentTwoDaysAgo();
        $exact = Dates::dateTime($comment->created_at);

        $this->assertFalse($this->user->fresh()->prefersExactDates());

        $this->actingAs($this->user)->get("/tasks/{$this->task->id}")
            ->assertSuccessful()
            ->assertSee('title="'.$exact.'">2 days ago</time>', false);
    }

    public function test_exact_mode_shows_the_date_with_the_relative_time_on_hover(): void
    {
        $this->user->update(['date_display' => User::DATE_DISPLAY_EXACT]);
        $comment = $this->commentTwoDaysAgo();
        $exact = Dates::dateTime($comment->created_at);

        $this->actingAs($this->user)->get("/tasks/{$this->task->id}")
            ->assertSuccessful()
            ->assertSee('title="2 days ago">'.$exact.'</time>', false);
    }

    public function test_a_freshly_posted_comment_follows_the_preference(): void
    {
        $this->user->update(['date_display' => User::DATE_DISPLAY_EXACT]);

        $response = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => '<p>Done</p>'])
            ->assertSuccessful();

        $comment = TaskComment::latest('id')->first();
        $response->assertJsonPath('comment.created_at', Dates::dateTime($comment->created_at))
            ->assertJsonPath('comment.created_at_hover', $comment->created_at->diffForHumans());
    }

    public function test_the_preference_is_saved_from_the_profile_page(): void
    {
        $this->actingAs($this->user)->get(route('profile.edit'))
            ->assertSuccessful()
            ->assertSee('name="date_display"', false);

        $this->actingAs($this->user)->put(route('profile.update'), [
            'name' => 'Pasha', 'email' => 'pasha@example.com', 'date_display' => 'exact',
        ])->assertRedirect();

        $this->assertTrue($this->user->fresh()->prefersExactDates());

        $this->actingAs($this->user)->put(route('profile.update'), [
            'name' => 'Pasha', 'email' => 'pasha@example.com', 'date_display' => 'sideways',
        ])->assertSessionHasErrors('date_display');

        $this->assertTrue($this->user->fresh()->prefersExactDates());
    }

    public function test_one_persons_choice_does_not_change_what_others_see(): void
    {
        $this->user->update(['date_display' => User::DATE_DISPLAY_EXACT]);
        $other = User::create(['name' => 'Lika', 'email' => 'lika@example.com', 'password' => bcrypt('secret')]);
        $when = now()->subDays(2);

        $this->assertSame(Dates::dateTime($when), Dates::agoParts($when, $this->user)[0]);
        $this->assertSame('2 days ago', Dates::agoParts($when, $other)[0]);
    }
}
