<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskAssigneeController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $this->authorizeManage($task);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $assignee = User::findOrFail($validated['user_id']);

        // syncWithoutDetaching keeps existing assignees and avoids duplicates.
        $changed = $task->assignees()->syncWithoutDetaching([$assignee->id]);

        if (! empty($changed['attached'])) {
            TaskActivity::record($task, TaskActivity::EVENT_ASSIGNED, [
                'meta' => ['name' => $assignee->name, 'user_id' => $assignee->id],
            ]);

            // Never self-notify.
            if ($assignee->id !== Auth::id()) {
                Notifier::send($assignee, new TaskAssignedNotification($task, Auth::user()));
            }
        }

        return response()->json([
            'success' => true,
            'assignee' => [
                'id' => $assignee->id,
                'name' => $assignee->name,
            ],
        ]);
    }

    public function destroy(Task $task, User $user)
    {
        $this->authorizeManage($task);

        if ($task->assignees()->detach($user->id) > 0) {
            TaskActivity::record($task, TaskActivity::EVENT_UNASSIGNED, [
                'meta' => ['name' => $user->name, 'user_id' => $user->id],
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * The task owner or a project manager may manage assignees.
     */
    private function authorizeManage(Task $task): void
    {
        abort_unless($task->isManageableBy(Auth::user()), 403);
    }
}
