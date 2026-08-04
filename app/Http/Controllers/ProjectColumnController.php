<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TaskStatus;
use App\Support\BoardColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The columns on one project's board.
 *
 * Managed by whoever manages the project. Until they touch it, the project
 * uses the shared columns and this controller has nothing to do.
 */
class ProjectColumnController extends Controller
{
    /**
     * Take the board into this project's own hands — a copy of the shared
     * columns, which changes nothing until something is edited.
     */
    public function adopt(Project $project)
    {
        $this->authorizeManage($project);

        BoardColumns::adopt($project);

        return back()->with('success', 'This board now keeps its own columns.');
    }

    /**
     * Put it back on the shared columns.
     */
    public function release(Project $project)
    {
        $this->authorizeManage($project);

        BoardColumns::release($project);

        return back()->with('success', 'This board is back on the shared columns.');
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeManage($project);

        // Adding a column to a board still on the shared set would give it one
        // column and lose every task; take a copy first.
        BoardColumns::adopt($project);

        $data = $this->validated($request);

        $project->statuses()->create($data + ['key' => $this->uniqueKey($project, $data['label'])]);

        return back()->with('success', 'Column "'.$data['label'].'" added.');
    }

    public function update(Request $request, TaskStatus $column)
    {
        $this->authorizeColumn($column);

        // The key stays put: it is what every task in the column is standing
        // on, and renaming a column must not move anybody.
        $column->update($this->validated($request));

        return back()->with('success', 'Column updated.');
    }

    public function destroy(TaskStatus $column)
    {
        $this->authorizeColumn($column);

        try {
            BoardColumns::remove($column);
        } catch (RuntimeException $e) {
            return back()->withErrors(['column' => $e->getMessage()]);
        }

        return back()->with('success', 'Column removed. Anything in it moved to the board\'s default column.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:40',
            'color' => ['required', Rule::in(array_keys(TaskStatus::palette()))],
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_completed' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        return [
            'label' => $data['label'],
            'color' => $data['color'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_completed' => (bool) ($data['is_completed'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }

    private function uniqueKey(Project $project, string $label): string
    {
        $base = Str::snake(Str::ascii($label)) ?: 'column';
        $key = $base;
        $suffix = 2;

        while ($project->statuses()->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    private function authorizeColumn(TaskStatus $column): void
    {
        // Shared columns belong to the whole app and are managed in the admin
        // panel; a project may only rearrange its own.
        abort_unless($column->isPrivate(), 403);

        $this->authorizeManage($column->project);
    }

    private function authorizeManage(?Project $project): void
    {
        abort_if($project === null, 404);
        abort_unless($project->isManagedBy(Auth::user()), 403);
    }
}
