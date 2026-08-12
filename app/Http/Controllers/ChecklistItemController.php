<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChecklistItemController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'task_id' => 'required|exists:tasks,id',
        ]);

        // The same rule as the task page itself. Without it, anyone signed
        // in could write onto any task's checklist by guessing an id.
        $task = Task::findOrFail($request->task_id);
        abort_unless($task->isAccessibleBy(Auth::user()), 403);

        $checklistItem = ChecklistItem::create([
            'task_id' => $task->id,
            'name' => $request->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $checklistItem,
        ]);
    }

    public function updateStatus(ChecklistItem $checklistItem)
    {
        $this->authorizeItem($checklistItem);

        $checklistItem->update([
            'completed' => ! $checklistItem->completed === true ? 1 : 0,
        ]);

        return response()->json([
            'success' => true,
            'status' => 200,
        ]);
    }

    public function update(Request $request, ChecklistItem $checklistItem)
    {
        $this->authorizeItem($checklistItem);

        $request->validate(['name' => 'required|string|max:255']);

        $checklistItem->update([
            'completed' => $request->has('completed'),
            'name' => $request->name,
        ]);

        return back()->with('success', 'Checklist item updated successfully.');
    }

    public function destroy(ChecklistItem $checklistItem)
    {
        $this->authorizeItem($checklistItem);

        $checklistItem->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function authorizeItem(ChecklistItem $checklistItem): void
    {
        abort_unless(
            $checklistItem->task !== null && $checklistItem->task->isAccessibleBy(Auth::user()),
            403,
        );
    }
}
