<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
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

        // Only notify on a genuinely new assignment, and never self-notify.
        if (! empty($changed['attached']) && $assignee->id !== Auth::id()) {
            $assignee->notify(new TaskAssignedNotification($task, Auth::user()));
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

        $task->assignees()->detach($user->id);

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
