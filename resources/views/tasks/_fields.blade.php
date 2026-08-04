{{--
    The inputs for whatever fields a project keeps on its tasks.

    Every project the form can choose from gets its own block; the one that
    matches the chosen project is shown. Blocks for the others stay in the
    document and are simply hidden — they submit nothing that matters, because
    a task only ever answers its own project's fields.

    Expects: $projects (with `fields` loaded), and optionally $task.
--}}
@php
    $selectedProject = old('project_id', $task->project_id ?? ($project->id ?? null));
    $answers = isset($task) ? $task->fieldValues->keyBy('project_field_id') : collect();
@endphp

@foreach($projects as $fieldProject)
    @continue($fieldProject->fields->isEmpty())
    <div class="cu-project-fields"
         data-project-fields="{{ $fieldProject->id }}"
         @style(['display:none' => (string) $selectedProject !== (string) $fieldProject->id])>
        @foreach($fieldProject->fields as $field)
            @php
                $chosen = (array) old('fields.'.$field->id, $answers->get($field->id)?->list() ?? []);
            @endphp
            <div class="form-group mb-3">
                <label class="form-label" for="field_{{ $field->id }}">{{ $field->name }}</label>
                @if($field->isMultiple())
                    <select name="fields[{{ $field->id }}][]" id="field_{{ $field->id }}"
                            class="form-control cu-input cu-select" multiple
                            size="{{ min(6, max(3, count($field->choices()))) }}">
                        @foreach($field->choices() as $choice)
                            <option value="{{ $choice }}" @selected(in_array($choice, $chosen, true))>{{ $choice }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Hold ⌘ / Ctrl to pick more than one.</small>
                @elseif($field->isChoice())
                    <select name="fields[{{ $field->id }}]" id="field_{{ $field->id }}" class="form-control cu-input cu-select">
                        <option value="">—</option>
                        @foreach($field->choices() as $choice)
                            <option value="{{ $choice }}" @selected(in_array($choice, $chosen, true))>{{ $choice }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="text" name="fields[{{ $field->id }}]" id="field_{{ $field->id }}"
                           class="form-control cu-input" maxlength="255" value="{{ $chosen[0] ?? '' }}">
                @endif
            </div>
        @endforeach
    </div>
@endforeach

@once
@push('scripts')
<script>
// Show the block belonging to the project the form is pointing at.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function (form) {
        const groups = form.querySelectorAll('[data-project-fields]');
        if (!groups.length) return;

        const picker = form.querySelector('[name="project_id"]');
        const sync = function () {
            const chosen = picker ? String(picker.value) : '';
            groups.forEach(function (group) {
                group.style.display = group.dataset.projectFields === chosen ? '' : 'none';
            });
        };

        picker?.addEventListener('change', sync);
        sync();
    });
});
</script>
@endpush
@endonce
