{{--
    The inputs for whatever fields a project keeps on its tasks.

    Every project the form can choose from gets its own block; the one that
    matches the chosen project is shown. Blocks for the others stay in the
    document and are simply hidden — they submit nothing that matters, because
    a task only ever answers its own project's fields.

    The heading and the prompt are not decoration. Before them, a form with no
    project chosen yet showed nothing at all where the fields would be, and
    "this project has fields" was indistinguishable from "you have not picked
    a project yet" — which read, reasonably, as the fields being someone
    else's to fill in.

    Expects: $projects (with `fields` loaded), and optionally $task.
--}}
@php
    $selectedProject = old('project_id', $task->project_id ?? ($project->id ?? null));
    $answers = isset($task) ? $task->fieldValues->keyBy('project_field_id') : collect();
    $withFields = collect($projects)->filter(fn ($p) => $p->fields->isNotEmpty());
@endphp

@if($withFields->isNotEmpty())
<div class="cu-fields-block" data-project-fields-block>
    <label class="form-label cu-label" style="margin-bottom:8px;">
        <i class="bi bi-ui-radios"></i> Project fields
    </label>

    {{-- Shown while no project is chosen, so the section is never a blank gap. --}}
    <p class="cu-fields-prompt" data-project-fields-prompt
       style="font-size:12px;color:#8a8f98;margin:0;{{ (string) $selectedProject !== '' ? 'display:none;' : '' }}">
        Pick a project above and whatever it records on its tasks appears here.
    </p>

    @foreach($withFields as $fieldProject)
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
</div>
@endif

@once
@push('scripts')
<script>
// Show the block belonging to the project the form is pointing at.
//
// Re-run on every way a form can appear, not only once at load: the create
// form lives inside a modal, and a block that was synced while the modal was
// still closed stays hidden for the life of the page.
(function () {
    function syncFieldBlocks(root) {
        (root || document).querySelectorAll('form').forEach(function (form) {
            const groups = form.querySelectorAll('[data-project-fields]');
            if (!groups.length) return;

            const picker = form.querySelector('[name="project_id"]');
            const prompt = form.querySelector('[data-project-fields-prompt]');

            const sync = function () {
                const chosen = picker ? String(picker.value) : '';
                let matched = false;
                groups.forEach(function (group) {
                    const show = group.dataset.projectFields === chosen;
                    group.style.display = show ? '' : 'none';
                    matched = matched || show;
                });
                if (prompt) prompt.style.display = matched ? 'none' : '';
            };

            if (picker && !picker.dataset.fieldsBound) {
                picker.dataset.fieldsBound = '1';
                picker.addEventListener('change', sync);
            }

            sync();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncFieldBlocks();
        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('shown.bs.modal', function () { syncFieldBlocks(modal); });
        });
    });
})();
</script>
@endpush
@endonce
