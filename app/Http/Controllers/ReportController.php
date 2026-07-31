<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Reports\PdfExport;
use App\Support\Reports\SpreadsheetExport;
use App\Support\Reports\TaskReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $report = new TaskReport(Auth::user(), $this->filters($request));

        return view('reports.index', array_merge(
            ['report' => $report, 'summary' => $report->summary()],
            $this->filterOptions(),
        ));
    }

    /**
     * The same report, as a file.
     */
    public function export(Request $request, string $format)
    {
        $report = new TaskReport(Auth::user(), $this->filters($request));

        return match ($format) {
            'xlsx' => (new SpreadsheetExport($report))->download(),
            'pdf' => (new PdfExport($report))->download(),
            default => abort(404),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only([
            'project_id', 'user_id', 'status', 'priority',
            'date_field', 'from', 'to', 'search',
        ]);
    }

    /**
     * What the filter form may offer. A person cannot filter by a project they
     * have no part in — that would be a way of asking whether it exists.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        $user = Auth::user();

        $projects = $user->isSuperAdmin()
            ? Project::orderBy('name')->get(['id', 'name'])
            : Project::where('user_id', $user->id)
                ->orWhereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get(['id', 'name']);

        return [
            'projects' => $projects,
            'people' => User::orderBy('name')->get(['id', 'name']),
            'statuses' => TaskStatus::ordered(),
            'dateFields' => TaskReport::DATE_FIELDS,
        ];
    }
}
