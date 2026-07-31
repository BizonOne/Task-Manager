<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(?Project $project = null)
    {
        $user = Auth::user();

        if ($project) {
            // A project's board shows every task in the project to any member,
            // not just the viewer's own — that's the point of collaborating.
            abort_unless($project->isAccessibleBy($user), 403);
            $tasks = Task::where('project_id', $project->id)
                ->with('project')
                ->get()
                ->groupBy('status');
        } else {
            // "My tasks": tasks the user owns or is assigned to, in active
            // projects. A super admin oversees everything and sees every task.
            $tasks = Task::when(! $user->isSuperAdmin(), function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id));
                });
            })
                ->whereHas('project', function ($query) {
                    $query->whereNotIn('status', ['completed', 'closed']);
                })
                ->with('project')
                ->get()
                ->groupBy('status');
        }

        // Projects the user can pick from: ones they own or are a member of —
        // or every active project, for a super admin.
        $projects = Project::when(! $user->isSuperAdmin(), function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('users', fn ($sub) => $sub->where('users.id', $user->id));
            });
        })
            ->whereNotIn('status', ['completed', 'closed'])
            ->get();
        $users = User::all();
        // Board columns come from the admin-managed status list.
        $statuses = TaskStatus::ordered();

        return view('tasks.index', compact('tasks', 'projects', 'users', 'project', 'statuses'));
    }

    public function create()
    {
        // Only projects this user may create in — Project::all() listed every
        // project in the system, leaking names to people with no access.
        $projects = $this->availableProjects();
        $users = User::orderBy('name')->get();

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
            'status' => ['required', TaskStatus::validationRule()],
            'estimated_hours' => 'nullable|numeric|min:0.5',
        ]);

        // You may only create tasks inside a project you own or belong to.
        $targetProject = Project::findOrFail($request->project_id);
        abort_unless($targetProject->isAccessibleBy(Auth::user()), 403);

        // Honour the chosen owner, but only someone who can actually reach the
        // project. (Forcing the creator here silently discarded the person
        // picked in the form, so every task had to be reassigned afterwards.)
        $owner = User::findOrFail($request->user_id);

        if (! $targetProject->isAccessibleBy($owner)) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'That person is not a member of this project.']);
        }

        Task::create(array_merge($request->all(), ['user_id' => $owner->id]));

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
        $task->load('user', 'project', 'checklistItems', 'assignees', 'comments.user', 'activities.user');

        // The task owner or a project manager may add/remove assignees.
        $canManageAssignees = $task->isManageableBy(Auth::user());

        // Everyone who can be mentioned or assigned.
        $mentionables = $task->participants();
        $assignableUsers = User::orderBy('name')->get(['id', 'name']);
        $statuses = TaskStatus::ordered();
        // Full history: field changes, assignments and comments in one list.
        $timeline = $task->timeline();

        return view('tasks.show', compact('task', 'canManageAssignees', 'mentionables', 'assignableUsers', 'statuses', 'timeline'));
    }

    public function edit(Task $task)
    {
        $this->authorizeManage($task);
        $projects = $this->availableProjects();
        $users = User::orderBy('name')->get();

        return view('tasks.edit', compact('task', 'projects', 'users'));
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeManage($task);
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'priority' => 'required|in:low,medium,high',
            'status' => ['required', TaskStatus::validationRule()],
            'estimated_hours' => 'nullable|numeric|min:0.5',
            'user_id' => 'nullable|exists:users,id',
        ]);

        // The edit form can reassign the task, so hold the new owner to the
        // same rule as creating one: they must be able to reach the project.
        if ($request->filled('user_id') && (int) $request->user_id !== $task->user_id) {
            $owner = User::findOrFail($request->user_id);

            if (! $task->project || ! $task->project->isAccessibleBy($owner)) {
                return back()
                    ->withInput()
                    ->withErrors(['user_id' => 'That person is not a member of this project.']);
            }
        }

        $task->update($request->all());

        return redirect()->route('tasks.show', $task->id)->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        $this->authorizeManage($task);
        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        // Collaborators (assignees, project team) can move the task too.
        abort_unless($task->isAccessibleBy(Auth::user()), 403);
        $request->validate([
            'status' => ['required', TaskStatus::validationRule()],
        ]);

        $task->status = $request->input('status');
        $task->save();

        return response()->json(['message' => 'Task status updated successfully.']);
    }

    /**
     * Projects the signed-in user may put a task in.
     */
    private function availableProjects()
    {
        $user = Auth::user();

        return Project::when(! $user->isSuperAdmin(), function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('users', fn ($sub) => $sub->where('users.id', $user->id));
            });
        })->orderBy('name')->get();
    }

    /**
     * Ensure the authenticated user may manage the task: its creator, or a
     * manager of the parent project.
     */
    private function authorizeManage(Task $task): void
    {
        abort_unless($task->isManageableBy(Auth::user()), 403);
    }
}
