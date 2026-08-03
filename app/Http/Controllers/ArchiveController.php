<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Archive;
use App\Support\Reports\TaskReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Finished work, filed away but not lost.
 *
 * Built on the same TaskReport as the reports page, so the archive and a report
 * over the same filters cannot disagree about what is in there.
 */
class ArchiveController extends Controller
{
    public function index(Request $request)
    {
        $filters = array_merge($request->only([
            'project_id', 'user_id', 'priority', 'date_field', 'from', 'to', 'search',
        ]), [
            // The archive shows the archive. That is not negotiable from the
            // query string.
            'archive' => 'archived',
        ]);

        $report = new TaskReport(Auth::user(), $filters);
        $user = Auth::user();

        $projects = $user->isSuperAdmin()
            ? Project::orderBy('name')->get(['id', 'name'])
            : Project::where('user_id', $user->id)
                ->orWhereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get(['id', 'name']);

        return view('archive.index', [
            'report' => $report,
            'tasks' => $report->tasks(),
            'projects' => $projects,
            'people' => User::orderBy('name')->get(['id', 'name']),
            'statuses' => TaskStatus::ordered(),
            'dateFields' => TaskReport::DATE_FIELDS,
            'afterDays' => Archive::afterDays(),
        ]);
    }

    public function store(Request $request, Task $task)
    {
        abort_unless(Archive::isManageableBy($task), 403);

        Archive::archive($task);

        return $this->respond($request, $task, 'Task moved to the archive.');
    }

    public function destroy(Request $request, Task $task)
    {
        abort_unless(Archive::isManageableBy($task), 403);

        Archive::restore($task);

        return $this->respond($request, $task, 'Task brought back from the archive.');
    }

    private function respond(Request $request, Task $task, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'archived' => $task->fresh()->isArchived(),
            ]);
        }

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', $message);
    }
}
