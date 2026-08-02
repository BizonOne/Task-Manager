<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaskLinkController extends Controller
{
    /**
     * Tasks the signed-in person may link to, for the picker.
     */
    public function search(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();
        abort_unless($task->isAccessibleBy($user), 403);

        $term = trim((string) $request->query('q', ''));

        $matches = Task::with('project')
            ->where('id', '!=', $task->id)
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', '%'.$term.'%');
                    // People refer to tasks by number as often as by name.
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->limit(50)
            ->get()
            // Access is per task, so it cannot be a WHERE clause; filter after
            // fetching and keep the page's worth that survives.
            ->filter(fn (Task $candidate) => $candidate->isAccessibleBy($user))
            ->take(15)
            ->map(fn (Task $candidate) => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'project' => $candidate->project?->name,
                'status' => $candidate->status_label,
            ])
            ->values();

        return response()->json(['tasks' => $matches]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();
        abort_unless($task->isAccessibleBy($user), 403);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(TaskLink::options()))],
            'linked_task_id' => [
                'required', 'integer', 'exists:tasks,id',
                // Not `different`, which compares two request fields rather
                // than a field against a value.
                Rule::notIn([$task->id]),
            ],
        ], [
            'linked_task_id.not_in' => 'A task cannot be linked to itself.',
        ]);

        [$type, $swap] = TaskLink::resolveOption($validated['type']);

        $other = Task::findOrFail($validated['linked_task_id']);
        // Linking exposes the other task's title on this page, so the person
        // has to be able to see it in the first place.
        abort_unless($other->isAccessibleBy($user), 403);

        // "B is blocked by A" is stored as "A blocks B" with the ends swapped.
        [$from, $to] = $swap ? [$other, $task] : [$task, $other];

        $existing = TaskLink::where('type', $type)
            ->where(function ($query) use ($from, $to) {
                $query->where(['task_id' => $from->id, 'linked_task_id' => $to->id])
                    ->orWhere(['task_id' => $to->id, 'linked_task_id' => $from->id]);
            })
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'These tasks are already linked that way.',
            ], 422);
        }

        $link = TaskLink::create([
            'task_id' => $from->id,
            'linked_task_id' => $to->id,
            'type' => $type,
            'created_by' => $user->id,
        ]);

        // Both histories mention it, each in its own words.
        $this->recordOnBothSides($link, TaskActivity::EVENT_LINKED);

        return response()->json([
            'success' => true,
            'link' => $this->toJson($link, $task),
        ]);
    }

    public function destroy(TaskLink $link): JsonResponse
    {
        $user = Auth::user();

        // Either end may cut the link, as long as they can manage that end.
        $canRemove = $link->task?->isManageableBy($user)
            || $link->linkedTask?->isManageableBy($user)
            || $link->created_by === $user->id;

        abort_unless($canRemove, 403);

        $this->recordOnBothSides($link, TaskActivity::EVENT_UNLINKED);
        $link->delete();

        return response()->json(['success' => true]);
    }

    /**
     * What the task page needs to render a link row.
     *
     * @return array<string, mixed>
     */
    public function toJson(TaskLink $link, Task $from): array
    {
        $other = $link->otherEnd($from);

        return [
            'id' => $link->id,
            'label' => $link->labelFor($from),
            'icon' => $link->icon,
            'task' => [
                'id' => $other->id,
                'title' => $other->title,
                'status' => $other->status_label,
                'status_key' => $other->status,
                'url' => route('tasks.show', $other),
                'project' => $other->project?->name,
            ],
        ];
    }

    private function recordOnBothSides(TaskLink $link, string $event): void
    {
        foreach ([$link->task, $link->linkedTask] as $side) {
            if ($side === null) {
                continue;
            }

            $other = $link->otherEnd($side);

            TaskActivity::record($side, $event, [
                'meta' => [
                    'label' => $link->labelFor($side),
                    'task_id' => $other?->id,
                    'title' => $other?->title,
                ],
            ]);
        }
    }
}
