<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The fields a project keeps on its tasks.
 *
 * Managed by whoever manages the project — the person who set it up knows
 * what their team needs to record, and waiting on an administrator to add a
 * dropdown is how teams end up keeping it in the title instead.
 */
class ProjectFieldController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorizeManage($project);

        $data = $this->validated($request);

        $project->fields()->create($data);

        return back()->with('success', 'Field "'.$data['name'].'" added.');
    }

    public function update(Request $request, ProjectField $field)
    {
        $this->authorizeManage($field->project);

        $field->update($this->validated($request));

        return back()->with('success', 'Field updated.');
    }

    public function destroy(ProjectField $field)
    {
        $this->authorizeManage($field->project);

        $name = $field->name;

        // The answers go with it — they mean nothing without the question.
        $field->delete();

        return back()->with('success', 'Field "'.$name.'" removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'type' => ['required', Rule::in(array_keys(ProjectField::types()))],
            // One choice per line: a textarea is what people reach for, and a
            // comma is a legitimate character inside a choice.
            'options' => 'nullable|string|max:4000',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'show_on_card' => 'sometimes|boolean',
        ]);

        $choices = collect(preg_split('/\r\n|\r|\n/', (string) ($data['options'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($choices === [] && $data['type'] !== ProjectField::TYPE_TEXT) {
            throw ValidationException::withMessages([
                'options' => 'A pick-one or pick-several field needs at least one choice.',
            ]);
        }

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'options' => $data['type'] === ProjectField::TYPE_TEXT ? null : $choices,
            'sort_order' => $data['sort_order'] ?? 0,
            'show_on_card' => (bool) ($data['show_on_card'] ?? false),
        ];
    }

    private function authorizeManage(?Project $project): void
    {
        abort_if($project === null, 404);
        abort_unless($project->isManagedBy(Auth::user()), 403);
    }
}
