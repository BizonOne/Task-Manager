<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\ProjectFields;
use App\Support\Tags;
use App\Support\Uploads;
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
                // The archive exists so finished work stops crowding the board.
                ->active()
                // Assignees, so the board can be filtered by who is actually on
                // a task and not only by who owns it.
                ->with(['project.fields', 'assignees', 'creator', 'fieldValues', 'tags'])
                ->get()
                ->groupBy('status');
        } else {
            // "My tasks", in active projects. A super admin oversees
            // everything and sees every task.
            //
            // involving(): raised by you, assigned to you, or added to you.
            // Work you raised for a colleague belongs on your board too — you
            // asked for it, and you are the one waiting on it.
            $tasks = Task::active()->when(
                ! $user->isSuperAdmin(),
                fn ($query) => $query->involving($user),
            )
                ->whereHas('project', function ($query) {
                    $query->whereNotIn('status', ['completed', 'closed']);
                })
                ->with(['project.fields', 'assignees', 'creator', 'fieldValues', 'tags'])
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
        // A project's board shows that project's columns; "my tasks" mixes
        // work from everywhere, so it shows the union — a column missing there
        // is a task nobody can see.
        $statuses = $project
            ? TaskStatus::ordered($project->id)
            : TaskStatus::forProjects(collect($tasks)->flatten()->pluck('project_id')->filter()->unique());
        // The projects on screen decide which of their own fields can be
        // filtered on: one project's board offers that project's fields, and
        // "my tasks" offers whatever the projects it is showing have.
        $projects->load('fields');
        $fieldFilters = ProjectFields::filterable($project ? [$project->load('fields')] : $projects);
        // Tags are shared by every board, so the dropdown offers the ones
        // actually on screen rather than every tag anyone has ever typed.
        $tagFilters = collect($tasks)->flatten()->flatMap->tags->unique('id')->sortBy('name')->values();
        // Everything anyone has used, so typing an existing tag is a choice
        // rather than a spelling test.
        $tagVocabulary = Tag::orderBy('name')->get();

        return view('tasks.index', compact('tasks', 'projects', 'users', 'project', 'statuses', 'fieldFilters', 'tagFilters', 'tagVocabulary'));
    }

    public function create()
    {
        // Only projects this user may create in — Project::all() listed every
        // project in the system, leaking names to people with no access.
        $projects = $this->availableProjects()->load('fields');
        $users = User::orderBy('name')->get();
        $tagVocabulary = Tag::orderBy('name')->get();

        return view('tasks.create', compact('projects', 'users', 'tagVocabulary'));
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
            // Left out, a task starts in whatever column that board starts
            // work in. Validated against the target project's board below.
            'status' => ['nullable', 'string'],
            'estimated_hours' => 'nullable|numeric|min:0.5',
            'tags' => 'nullable|string|max:400',
            'attachments' => 'sometimes|array|max:10',
            'attachments.*' => Uploads::rule(),
        ]);

        // You may only create tasks inside a project you own or belong to.
        $targetProject = Project::findOrFail($request->project_id);
        abort_unless($targetProject->isAccessibleBy(Auth::user()), 403);

        // Columns belong to a board, so which ones are valid depends on which
        // board the task is landing on.
        $request->merge([
            'status' => $request->input('status') ?: TaskStatus::defaultKey($targetProject->id),
        ]);
        $request->validate(['status' => TaskStatus::validationRule($targetProject->id)]);

        // Honour the chosen owner, but only someone who can actually reach the
        // project. (Forcing the creator here silently discarded the person
        // picked in the form, so every task had to be reassigned afterwards.)
        $owner = User::findOrFail($request->user_id);

        if (! $targetProject->isAccessibleBy($owner)) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'That person is not a member of this project.']);
        }

        $task = Task::create(array_merge($request->except(['attachments', 'fields', 'tags']), ['user_id' => $owner->id]));

        // Whatever this project decided to record on its tasks, and whatever
        // the person filing it wanted to write on this one.
        ProjectFields::apply($task, $request->input('fields'));
        Tags::apply($task, $request->input('tags'));

        // The spec usually exists before the task does, so a file can come
        // along with it rather than needing a second trip.
        foreach ($request->file('attachments') ?: [] as $upload) {
            Uploads::store($upload, Auth::user(), task: $task);
        }

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
        $task->load('user', 'project.fields', 'fieldValues', 'tags', 'checklistItems', 'assignees', 'comments.user', 'comments.files', 'activities.user', 'files.user',
            'outgoingLinks.linkedTask.project', 'incomingLinks.task.project');

        // The task owner or a project manager may add/remove assignees.
        $canManageAssignees = $task->isManageableBy(Auth::user());

        // Everyone who can be mentioned or assigned.
        $mentionables = $task->participants();
        $assignableUsers = User::orderBy('name')->get(['id', 'name']);
        $statuses = TaskStatus::ordered($task->project_id);
        // Full history: field changes, assignments and comments in one list.
        $timeline = $task->timeline();
        // Relations, worded from this task's side and grouped by that wording.
        $linkGroups = $task->groupedLinks(Auth::user());

        return view('tasks.show', compact('task', 'canManageAssignees', 'mentionables', 'assignableUsers', 'statuses', 'timeline', 'linkGroups'));
    }

    public function edit(Task $task)
    {
        $this->authorizeManage($task);
        $projects = $this->availableProjects()->load('fields');
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
            'status' => ['required', TaskStatus::validationRule(
                (int) ($request->input('project_id') ?: $task->project_id) ?: null
            )],
            'estimated_hours' => 'nullable|numeric|min:0.5',
            'user_id' => 'nullable|exists:users,id',
            'tags' => 'nullable|string|max:400',
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

        $task->update($request->except(['fields', 'tags']));

        ProjectFields::apply($task, $request->input('fields'));
        Tags::apply($task, $request->input('tags'));

        return redirect()->route('tasks.show', $task->id)->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        $this->authorizeManage($task);

        // Comment attachments cascade at the database level, which never fires
        // a model event — so clear the stored objects here or they stay in the
        // bucket with nothing pointing at them. Files uploaded from the Files
        // page are only unhooked, not destroyed: they are still someone's.
        foreach ($task->files()->whereNotNull('task_comment_id')->get() as $file) {
            Uploads::deleteStored($file);
        }

        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        // Collaborators (assignees, project team) can move the task too.
        abort_unless($task->isAccessibleBy(Auth::user()), 403);
        $request->validate([
            'status' => ['required', TaskStatus::validationRule($task->project_id)],
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
