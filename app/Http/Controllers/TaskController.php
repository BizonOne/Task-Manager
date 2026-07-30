<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(?Project $project = null)
    {
        $user = Auth::user();

        if ($project) {
            // Show tasks for a specific project (closed projects still accessible directly)
            $tasks = Task::where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->with('project')
                ->get()
                ->groupBy('status');
        } else {
            // Show all tasks — exclude tasks from completed or closed projects
            $tasks = Task::where('user_id', $user->id)
                ->whereHas('project', function ($query) {
                    $query->whereNotIn('status', ['completed', 'closed']);
                })
                ->with('project')
                ->get()
                ->groupBy('status');
        }

        // Only show projects whose tasks are actually loaded (exclude completed & closed)
        $projects = Project::whereNotIn('status', ['completed', 'closed'])->get();
        $users = User::all();

        return view('tasks.index', compact('tasks', 'projects', 'users', 'project'));
    }

    public function create()
    {
        $projects = Project::all();
        $users = User::all();

        return view('tasks.create', compact('projects', 'users'));
    }

    public function store(Request $request, ?Project $project = null)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'priority' => 'required|in:low,medium,high',
            'status' => 'required|in:to_do,in_progress,on_hold,in_review,completed',
            'estimated_hours' => 'nullable|numeric|min:0.5',
        ]);

        // Only allow creating tasks inside a project the user owns, and always
        // own the created task — never trust an arbitrary user_id from the form.
        $targetProject = Project::findOrFail($request->project_id);
        abort_unless($targetProject->user_id === Auth::id(), 403);

        Task::create(array_merge($request->all(), ['user_id' => Auth::id()]));

        // Redirect based on context
        if ($project) {
            return redirect()->route('projects.tasks.index', $project)->with('success', 'Task created successfully.');
        } else {
            return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
        }
    }

    public function show(Task $task)
    {
        // Owner, project owner, assignees and project team can all view a task.
        abort_unless($task->isAccessibleBy(Auth::user()), 403);
        $task->load('user', 'project', 'checklistItems', 'assignees', 'comments.user');

        // Only the task owner or project owner may add/remove assignees.
        $canManageAssignees = $task->user_id === Auth::id() || $task->project?->user_id === Auth::id();

        // Everyone who can be mentioned or assigned.
        $mentionables = $task->participants();
        $assignableUsers = User::orderBy('name')->get(['id', 'name']);

        return view('tasks.show', compact('task', 'canManageAssignees', 'mentionables', 'assignableUsers'));
    }

    public function edit(Task $task)
    {
        $this->authorizeOwner($task);
        $projects = Project::all();
        $users = User::all();

        return view('tasks.edit', compact('task', 'projects', 'users'));
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeOwner($task);
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'priority' => 'required|in:low,medium,high',
            'status' => 'required|in:to_do,in_progress,on_hold,in_review,completed',
            'estimated_hours' => 'nullable|numeric|min:0.5',
        ]);

        $task->update($request->all());

        return redirect()->route('tasks.show', $task->id)->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        $this->authorizeOwner($task);
        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        // Collaborators (assignees, project team) can move the task too.
        abort_unless($task->isAccessibleBy(Auth::user()), 403);
        $request->validate([
            'status' => 'required|in:to_do,in_progress,on_hold,in_review,completed',
        ]);

        $task->status = $request->input('status');
        $task->save();

        return response()->json(['message' => 'Task status updated successfully.']);
    }

    /**
     * Ensure the authenticated user owns the given task.
     */
    private function authorizeOwner(Task $task): void
    {
        abort_unless($task->user_id === Auth::id(), 403);
    }
}
