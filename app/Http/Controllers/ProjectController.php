<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Notifications\AddedToProjectNotification;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index()
    {
        $me = Auth::id();
        $user = Auth::user();

        // Projects the user owns OR is a team member of. A super admin oversees
        // the whole workspace and sees every project.
        $projects = Project::when(! $user->isSuperAdmin(), function ($query) use ($me) {
            $query->where(function ($q) use ($me) {
                $q->where('user_id', $me)
                    ->orWhereHas('users', fn ($sub) => $sub->where('users.id', $me));
            });
        })
            // Counts by hardcoded status key used to hang off here. Nothing
            // rendered them, and with per-project columns they would have been
            // wrong for every board that renamed anything.
            ->with('users')
            ->latest()
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'required|in:not_started,in_progress,completed,closed',
        ]);

        Auth::user()->projects()->create($request->all());

        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }

    public function show(Project $project)
    {
        $this->authorizeAccess($project);
        $teamMembers = $project->users()->get();
        $users = User::all();
        $canManage = $project->isManagedBy(Auth::user());

        return view('projects.show', compact('project', 'teamMembers', 'users', 'canManage'));
    }

    public function edit(Project $project)
    {
        $this->authorizeManage($project);

        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeManage($project);
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'required|in:not_started,in_progress,completed,closed',
        ]);

        $project->update($request->all());

        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        // Deleting a project stays owner-only.
        $this->authorizeOwner($project);
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    public function addMember(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required|exists:users,id',
            'role' => 'nullable|in:member,manager',
        ]);

        $project = Project::findOrFail($request->project_id);
        $this->authorizeManage($project);

        $userId = (int) $request->user_id;

        // The owner is implicitly part of the project — no team row needed.
        if ($userId === $project->user_id) {
            return back()->with('error', 'The owner is already part of this project.');
        }

        $role = $request->input('role', 'member');
        $changed = $project->users()->syncWithoutDetaching([$userId => ['role' => $role]]);

        // Notify only on a genuinely new membership, never yourself.
        if (! empty($changed['attached']) && $userId !== Auth::id() && $member = User::find($userId)) {
            Notifier::send($member, new AddedToProjectNotification($project, Auth::user()));
        }

        return back()->with('success', 'Member added successfully.');
    }

    public function removeMember(Project $project, User $user)
    {
        $this->authorizeManage($project);
        $project->users()->detach($user->id);

        return back()->with('success', 'Member removed.');
    }

    /**
     * Owner or any team member may view the project.
     */
    private function authorizeAccess(Project $project): void
    {
        abort_unless($project->isAccessibleBy(Auth::user()), 403);
    }

    /**
     * Owner or a "manager" member may edit the project and manage members.
     */
    private function authorizeManage(Project $project): void
    {
        abort_unless($project->isManagedBy(Auth::user()), 403);
    }

    /**
     * Only the owner may delete the project.
     */
    private function authorizeOwner(Project $project): void
    {
        abort_unless(Auth::user()->isSuperAdmin() || $project->user_id === Auth::id(), 403);
    }
}
