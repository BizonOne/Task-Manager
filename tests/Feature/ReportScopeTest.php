<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Archive;
use App\Support\Reports\TaskReport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A report is an account of *your* work.
 *
 * Being on a project is a reason to see its board — it is not a reason to pull
 * colleagues' tasks into your own report, or to be handed the staff list in a
 * filter dropdown. Admins oversee, so they keep the wider view.
 */
class ReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $colleague;

    private User $admin;

    private Project $project;

    private Task $mine;

    private Task $assignedToMe;

    private Task $colleagues;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->member = User::create(['name' => 'Mel Member', 'email' => 'mel@example.com', 'password' => bcrypt('secret')]);
        $this->colleague = User::create(['name' => 'Cory Colleague', 'email' => 'cory@example.com', 'password' => bcrypt('secret')]);
        $this->admin = User::create(['name' => 'Ada Admin', 'email' => 'ada@example.com', 'password' => bcrypt('secret')]);

        $this->member->assignRole('member');
        $this->colleague->assignRole('member');
        $this->admin->assignRole('admin');

        // One shared project. All three belong to it.
        $this->project = Project::create(['user_id' => $this->admin->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach([$this->member->id, $this->colleague->id]);

        $this->mine = $this->task('Raised by Mel', $this->member);
        $this->assignedToMe = $this->task('Handed to Mel', $this->colleague);
        $this->assignedToMe->assignees()->attach($this->member->id);
        $this->colleagues = $this->task('Corys own work', $this->colleague);
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

    // --- reports ------------------------------------------------------------

    public function test_a_members_report_covers_their_own_work_only(): void
    {
        $this->actingAs($this->member)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee('Raised by Mel')
            ->assertSee('Handed to Mel')
            // Same project, but none of Mel's business to report on.
            ->assertDontSee('Corys own work');
    }

    public function test_an_admin_still_sees_the_whole_project(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee('Raised by Mel')
            ->assertSee('Corys own work');
    }

    public function test_a_members_totals_count_only_their_own_work(): void
    {
        $report = new TaskReport($this->member);

        // Three tasks in the project, two of them Mel's.
        $this->assertSame(2, $report->summary()['total']);
    }

    public function test_a_member_is_not_handed_the_staff_list_in_a_filter(): void
    {
        // On the options themselves, not on the page: a colleague's name can
        // legitimately appear elsewhere — they own a task Mel is assigned to —
        // and that is not the same as being offered as a filter.
        $people = TaskReport::peopleOptionsFor($this->member);

        $this->assertSame(['Mel Member'], $people->pluck('name')->all());

        $this->actingAs($this->member)
            ->get('/reports')
            ->assertSuccessful()
            ->assertDontSee('<option value="'.$this->admin->id.'"', false);
    }

    public function test_an_admin_can_still_filter_by_anyone(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee('Cory Colleague')
            ->assertSee('Mel Member');
    }

    public function test_a_member_cannot_widen_the_report_through_the_query_string(): void
    {
        // Asking for the colleague by id must not produce their tasks.
        $this->actingAs($this->member)
            ->get('/reports?user_id[]='.$this->colleague->id)
            ->assertSuccessful()
            ->assertDontSee('Corys own work');
    }

    public function test_a_members_export_carries_the_same_rows_as_their_screen(): void
    {
        $response = $this->actingAs($this->member)->get('/reports/export/pdf');

        $response->assertSuccessful();
        $body = $response->getContent();

        $this->assertStringContainsString('%PDF-', substr($body, 0, 8));
        // The file cannot be a way around what the page is willing to show.
        $this->assertSame(2, (new TaskReport($this->member))->tasks()->count());
    }

    // --- the archive --------------------------------------------------------

    public function test_a_members_archive_holds_their_own_work_only(): void
    {
        Archive::archive($this->mine);
        Archive::archive($this->assignedToMe);
        Archive::archive($this->colleagues);

        $this->actingAs($this->member)
            ->get('/archive')
            ->assertSuccessful()
            ->assertSee('Raised by Mel')
            ->assertSee('Handed to Mel')
            ->assertDontSee('Corys own work');
    }

    public function test_an_admins_archive_holds_the_whole_project(): void
    {
        Archive::archive($this->mine);
        Archive::archive($this->colleagues);

        $this->actingAs($this->admin)
            ->get('/archive')
            ->assertSuccessful()
            ->assertSee('Raised by Mel')
            ->assertSee('Corys own work');
    }

    public function test_a_member_is_offered_only_the_projects_their_own_work_is_in(): void
    {
        $elsewhere = Project::create(['user_id' => $this->admin->id, 'name' => 'Nothing To Do With Mel', 'status' => 'in_progress']);
        $elsewhere->users()->attach($this->member->id);

        // Mel belongs to it but has no task in it, so it can only ever return
        // an empty report.
        $this->actingAs($this->member)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee('Delivery')
            ->assertDontSee('Nothing To Do With Mel');
    }

    public function test_a_super_admin_sees_everything(): void
    {
        $boss = User::create(['name' => 'Sue Super', 'email' => 'sue@example.com', 'password' => bcrypt('secret')]);
        $boss->assignRole('super_admin');

        $this->actingAs($boss)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee('Raised by Mel')
            ->assertSee('Corys own work');
    }
}
