<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectField;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Reading and writing the answers to a project's own fields.
 *
 * One place, because four forms write them — creating a task from the board,
 * from the full form, editing one, and the Jira import — and a field that only
 * three of them understood would be a field nobody trusts.
 */
class ProjectFields
{
    /**
     * Write a task's answers from a form's `fields` input.
     *
     * A null input means the form never carried the section (an old page, an
     * API call); that is different from an empty one, which means "clear
     * them", so nothing is touched.
     *
     * @param  array<int|string, mixed>|null  $input  field id => value
     */
    public static function apply(Task $task, ?array $input): void
    {
        if ($input === null) {
            return;
        }

        foreach (self::fieldsFor($task) as $field) {
            $value = $field->normalise($input[$field->id] ?? null);

            if ($value === []) {
                $task->fieldValues()->where('project_field_id', $field->id)->delete();

                continue;
            }

            $task->fieldValues()->updateOrCreate(
                ['project_field_id' => $field->id],
                ['value' => $value],
            );
        }

        $task->unsetRelation('fieldValues');
    }

    /**
     * Set one field by name, creating the choice if the field can hold it.
     *
     * For the importer: it knows "Acquirer is GURUPAY" and neither the field
     * nor the option may exist yet.
     */
    public static function set(Task $task, ProjectField $field, array $values): void
    {
        if ($values === []) {
            $task->fieldValues()->where('project_field_id', $field->id)->delete();

            return;
        }

        $task->fieldValues()->updateOrCreate(
            ['project_field_id' => $field->id],
            ['value' => array_values($values)],
        );

        $task->unsetRelation('fieldValues');
    }

    /**
     * Drop answers that belong to some other project's fields.
     *
     * Moving a task to another project leaves its old answers pointing at
     * fields the new project has never heard of.
     */
    public static function forget(Task $task): int
    {
        $keep = self::fieldsFor($task)->pluck('id');

        return $task->fieldValues()->whereNotIn('project_field_id', $keep)->delete();
    }

    /**
     * The filter tokens a card carries, pipe-wrapped so "|acquirer::XS|"
     * cannot match "XSELL".
     */
    public static function tokens(Task $task): string
    {
        $parts = $task->fieldAnswers()
            ->flatMap(fn (array $answer) => array_map(
                fn (string $value) => $answer['field']->key.'::'.$value,
                $answer['value'],
            ));

        return '|'.$parts->implode('|').'|';
    }

    /**
     * The fields worth putting a filter on, and what each one can be filtered
     * to.
     *
     * Only the fields answered by choosing: a free-text field has no list to
     * offer, and a dropdown of every sentence anybody has typed is not a
     * filter.
     *
     * Built from whatever projects are on screen, which is what makes a field
     * added this morning a filter this morning — there is nothing to install
     * and no cache to clear. Fields sharing a key across projects become one
     * dropdown holding both sets of choices, rather than two that look
     * identical and quietly narrow each other.
     *
     * @param  Collection<int, Project>|iterable<Project>  $projects
     * @return Collection<int, array{key: string, name: string, choices: array<int, string>}>
     */
    public static function filterable(iterable $projects): Collection
    {
        return collect($projects)
            ->flatMap(fn ($project) => $project->fields)
            ->filter(fn (ProjectField $field) => $field->isChoice() && $field->choices() !== [])
            ->groupBy('key')
            ->map(fn (Collection $sameKey) => [
                'key' => (string) $sameKey->first()->key,
                'name' => (string) $sameKey->first()->name,
                'choices' => $sameKey->flatMap->choices()->unique()->values()->all(),
            ])
            ->values();
    }

    /**
     * @return Collection<int, ProjectField>
     */
    private static function fieldsFor(Task $task): Collection
    {
        $task->loadMissing('project.fields');

        return collect($task->project?->fields ?? []);
    }
}
