<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Reports\TaskReport;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $mate;

    private User $outsider;

    private Project $apollo;

    private Project $skunkworks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->mate = User::create(['name' => 'Mate', 'email' => 'mate@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Outsider', 'email' => 'out@example.com', 'password' => bcrypt('secret')]);

        $this->apollo = Project::create(['user_id' => $this->owner->id, 'name' => 'Apollo', 'status' => 'in_progress']);
        $this->apollo->users()->attach($this->mate->id, ['role' => 'member']);

        $this->skunkworks = Project::create(['user_id' => $this->outsider->id, 'name' => 'Skunkworks', 'status' => 'in_progress']);
    }

    private function task(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'user_id' => $this->owner->id,
            'project_id' => $this->apollo->id,
            'title' => 'A task',
            'priority' => 'medium',
            'status' => 'to_do',
        ], $attributes));
    }

    /* ── What a person may see ── */

    public function test_a_report_covers_only_the_tasks_you_can_reach(): void
    {
        $this->task(['title' => 'Apollo work']);
        Task::create([
            'user_id' => $this->outsider->id, 'project_id' => $this->skunkworks->id,
            'title' => 'Secret work', 'priority' => 'low', 'status' => 'to_do',
        ]);

        $titles = (new TaskReport($this->mate))->tasks()->pluck('title');

        $this->assertTrue($titles->contains('Apollo work'));
        $this->assertFalse($titles->contains('Secret work'), 'A report is not a way around project access.');
    }

    public function test_a_super_admin_sees_everything(): void
    {
        $admin = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => bcrypt('secret')]);
        $admin->assignRole('super_admin');

        $this->task(['title' => 'Apollo work']);
        Task::create([
            'user_id' => $this->outsider->id, 'project_id' => $this->skunkworks->id,
            'title' => 'Secret work', 'priority' => 'low', 'status' => 'to_do',
        ]);

        $this->assertSame(2, (new TaskReport($admin))->tasks()->count());
    }

    public function test_the_filter_form_only_offers_projects_you_belong_to(): void
    {
        $this->actingAs($this->mate)->get(route('reports.index'))
            ->assertSuccessful()
            ->assertSee('Apollo')
            ->assertDontSee('Skunkworks');
    }

    /* ── The numbers ── */

    public function test_the_summary_counts_what_it_says_it_counts(): void
    {
        $this->task(['title' => 'Done', 'status' => 'completed']);
        $this->task(['title' => 'Open', 'status' => 'in_progress', 'estimated_hours' => 3.5]);
        $this->task(['title' => 'Late', 'status' => 'to_do', 'due_date' => now()->subWeek(), 'estimated_hours' => 1.5]);
        // Finished, so being past its date does not make it overdue.
        $this->task(['title' => 'Late but done', 'status' => 'completed', 'due_date' => now()->subWeek()]);

        $summary = (new TaskReport($this->owner))->summary();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['completed']);
        $this->assertSame(2, $summary['open']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(50, $summary['completion_rate']);
        $this->assertSame(5.0, $summary['estimated_hours']);
    }

    public function test_filters_narrow_the_report(): void
    {
        $this->task(['title' => 'High one', 'priority' => 'high']);
        $this->task(['title' => 'Low one', 'priority' => 'low']);
        $this->task(['title' => 'Someone elses', 'user_id' => $this->mate->id, 'priority' => 'high']);

        $byPriority = new TaskReport($this->owner, ['priority' => ['high']]);
        $this->assertSame(2, $byPriority->summary()['total']);

        $byPerson = new TaskReport($this->owner, ['user_id' => [$this->mate->id]]);
        $this->assertSame(['Someone elses'], $byPerson->tasks()->pluck('title')->all());

        $bySearch = new TaskReport($this->owner, ['search' => 'Low']);
        $this->assertSame(['Low one'], $bySearch->tasks()->pluck('title')->all());
    }

    public function test_a_date_range_applies_to_the_chosen_field(): void
    {
        $this->task(['title' => 'Due soon', 'due_date' => now()->addDays(3)]);
        $this->task(['title' => 'Due later', 'due_date' => now()->addMonths(2)]);

        $report = new TaskReport($this->owner, [
            'date_field' => 'due_date',
            'from' => now()->toDateString(),
            'to' => now()->addWeek()->toDateString(),
        ]);

        $this->assertSame(['Due soon'], $report->tasks()->pluck('title')->all());
    }

    public function test_breakdowns_group_and_rate_correctly(): void
    {
        $this->task(['title' => 'One', 'status' => 'completed']);
        $this->task(['title' => 'Two', 'status' => 'to_do']);
        $this->task(['title' => 'Three', 'user_id' => $this->mate->id, 'status' => 'completed']);

        $byAssignee = (new TaskReport($this->owner))->byAssignee()->keyBy('label');

        $this->assertSame(2, $byAssignee['Owner']['count']);
        $this->assertSame(50, $byAssignee['Owner']['rate']);
        $this->assertSame(100, $byAssignee['Mate']['rate']);
    }

    public function test_the_description_says_what_was_filtered(): void
    {
        $report = new TaskReport($this->owner, [
            'project_id' => [$this->apollo->id],
            'priority' => ['high'],
        ]);

        $this->assertStringContainsString('Apollo', $report->describe());
        $this->assertStringContainsString('High', $report->describe());
        // With nothing filtered it still has to say what it covers.
        $this->assertSame('All tasks you have access to', (new TaskReport($this->owner))->describe());
    }

    /* ── Exports ── */

    public function test_the_spreadsheet_carries_the_tasks_and_the_totals(): void
    {
        $this->task(['title' => 'Ship the invoice run', 'status' => 'completed']);
        $this->task(['title' => 'Проверить отчёт', 'status' => 'to_do']);

        $response = $this->actingAs($this->owner)->get(route('reports.export', ['format' => 'xlsx']));
        $response->assertSuccessful();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $book = IOFactory::load($path);
        $this->assertSame(['Summary', 'Tasks'], $book->getSheetNames());

        $rows = $book->getSheetByName('Tasks')->toArray();
        $this->assertSame(['Key', 'Title', 'Project', 'Assignee', 'Status', 'Priority', 'Due', 'Created', 'Est. hours'], $rows[0]);

        $titles = array_column(array_slice($rows, 1), 1);
        $this->assertContains('Ship the invoice run', $titles);
        // Cyrillic has to survive the round trip, not come back as boxes.
        $this->assertContains('Проверить отчёт', $titles);

        $summary = $book->getSheetByName('Summary')->toArray();
        $this->assertSame('Task report', $summary[0][0]);

        unlink($path);
    }

    public function test_the_pdf_is_a_pdf_and_covers_the_same_filters(): void
    {
        $this->task(['title' => 'High one', 'priority' => 'high']);
        $this->task(['title' => 'Low one', 'priority' => 'low']);

        $response = $this->actingAs($this->owner)
            ->get(route('reports.export', ['format' => 'pdf', 'priority' => ['high']]));

        $response->assertSuccessful();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', $response->headers->get('content-disposition'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        // The renderer's default font has no Cyrillic, which would turn every
        // Russian title into boxes; DejaVu has to be the one embedded.
        $this->assertStringContainsString('DejaVu', $pdf);
    }

    public function test_the_pdf_embeds_a_font_that_can_draw_cyrillic(): void
    {
        $this->task(['title' => 'Проверить квартальный отчёт']);

        $pdf = $this->actingAs($this->owner)
            ->get(route('reports.export', ['format' => 'pdf']))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('DejaVuSans', str_replace(' ', '', $pdf));
    }

    public function test_an_export_cannot_reach_past_your_access(): void
    {
        Task::create([
            'user_id' => $this->outsider->id, 'project_id' => $this->skunkworks->id,
            'title' => 'Secret work', 'priority' => 'low', 'status' => 'to_do',
        ]);

        $response = $this->actingAs($this->mate)->get(route('reports.export', ['format' => 'xlsx']));

        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('Tasks')->toArray();

        $this->assertNotContains('Secret work', array_column(array_slice($rows, 1), 1));
        unlink($path);
    }

    public function test_an_unknown_export_format_is_a_404(): void
    {
        $this->actingAs($this->owner)->get('/reports/export/csv')->assertNotFound();
    }

    public function test_the_page_shows_the_tasks_and_offers_both_exports(): void
    {
        $this->task(['title' => 'Ship the invoice run']);

        $this->actingAs($this->owner)->get(route('reports.index'))
            ->assertSuccessful()
            ->assertSee('Ship the invoice run')
            ->assertSee('Excel')
            ->assertSee('PDF');
    }

    public function test_the_export_links_carry_the_current_filters(): void
    {
        $this->task(['title' => 'High one', 'priority' => 'high']);

        // Exporting has to give you what you are looking at, not everything.
        $this->actingAs($this->owner)
            ->get(route('reports.index', ['priority' => ['high']]))
            ->assertSuccessful()
            ->assertSee('priority%5B0%5D=high', escape: false);
    }
}
