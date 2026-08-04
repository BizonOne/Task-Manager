<x-filament-panels::page>
    @php
        $entities = \App\Support\Permissions::ENTITIES;
        $system = \App\Support\Permissions::SYSTEM;
        $scopeLabels = \App\Support\Permissions::SCOPE_LABELS;
        $scopeHelp = \App\Support\Permissions::SCOPE_HELP;
        $field = fn (string $key): string => \App\Filament\Pages\ManagePermissions::field($key);
    @endphp

    {{--
        Plain CSS on purpose. The panel ships a precompiled stylesheet, so a
        Tailwind class this app writes but Filament never uses is simply absent
        from the bundle — which is how this page first rendered as bare markup.
        Filament's own components and CSS variables are here; arbitrary
        utilities are not.
    --}}
    @push('styles')
        <style>
            .pm-scopes { display: flex; flex-direction: column; gap: .375rem; }
            .pm-check {
                display: flex; align-items: center; gap: .5rem;
                font-size: .875rem; line-height: 1.25rem;
                color: var(--gray-700); cursor: pointer; white-space: nowrap;
            }
            .dark .pm-check { color: var(--gray-300); }

            .pm-legend { display: grid; gap: .5rem; margin-bottom: 1rem; }
            @media (min-width: 768px) { .pm-legend { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
            .pm-legend-item {
                border: 1px solid var(--gray-200); border-radius: .75rem;
                padding: .75rem .875rem; background: var(--gray-50);
            }
            .dark .pm-legend-item { border-color: var(--gray-700); background: var(--gray-900); }
            .pm-legend-term { font-weight: 600; font-size: .8125rem; color: var(--gray-900); }
            .dark .pm-legend-term { color: var(--gray-100); }
            .pm-legend-desc { font-size: .8125rem; color: var(--gray-500); margin-top: .125rem; }

            .pm-system { display: grid; gap: .625rem; }
            @media (min-width: 640px) { .pm-system { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

            .pm-table-wrap { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .75rem; }
            .dark .pm-table-wrap { border-color: var(--gray-700); }
            .pm-table { width: 100%; border-collapse: collapse; }
            .pm-table th {
                text-align: left; padding: .75rem 1rem; font-size: .75rem; font-weight: 600;
                text-transform: uppercase; letter-spacing: .05em; color: var(--gray-500);
                background: var(--gray-50); border-bottom: 1px solid var(--gray-200);
                white-space: nowrap;
            }
            .dark .pm-table th { background: var(--gray-900); border-color: var(--gray-700); }
            .pm-table td { padding: .875rem 1rem; vertical-align: top; }
            .pm-table tbody tr + tr td { border-top: 1px solid var(--gray-200); }
            .dark .pm-table tbody tr + tr td { border-color: var(--gray-700); }
            .pm-table tbody tr:hover td { background: var(--gray-50); }
            .dark .pm-table tbody tr:hover td { background: color-mix(in srgb, var(--gray-900) 60%, transparent); }

            .pm-type { font-weight: 600; font-size: .875rem; color: var(--gray-900); }
            .dark .pm-type { color: var(--gray-100); }
            .pm-row-actions { display: flex; gap: .625rem; margin-top: .375rem; }
            .pm-row-action {
                font-size: .75rem; background: none; border: 0; padding: 0;
                cursor: pointer; color: var(--primary-600);
            }
            .pm-row-action:hover { text-decoration: underline; }
            .pm-row-action.pm-muted { color: var(--gray-400); }

            .pm-role { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
            .pm-role select {
                border: 1px solid var(--gray-300); border-radius: .5rem;
                padding: .5rem .75rem; font-size: .875rem; background: #fff; color: var(--gray-900);
            }
            .dark .pm-role select {
                border-color: var(--gray-600); background: var(--gray-900); color: var(--gray-100);
            }
        </style>
    @endpush

    <x-filament::section>
        <x-slot name="heading">Role</x-slot>
        <x-slot name="description">
            Permissions are set per role. <strong>super_admin</strong> is not listed: it
            bypasses every check by design, so a screen of ticked boxes for it would be
            a lie you could untick.
        </x-slot>

        <div class="pm-role">
            <select id="roleId" wire:model.live="roleId">
                @foreach($this->editableRoles() as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </select>
        </div>
    </x-filament::section>

    {{-- 1. The thing that caused the confusion in the first place. --}}
    <x-filament::section>
        <x-slot name="heading">Admin panel permissions</x-slot>

        {{-- The callout renders its text from the description slot, not the
             default one — the default slot came out empty. --}}
        <x-filament::callout icon="heroicon-o-information-circle">
            <x-slot name="description">
                The permission list under <em>Roles</em> governs <strong>this admin panel only</strong> —
                which resources a role may open here. It has never controlled the task manager
                itself. What follows on this page does.
            </x-slot>
        </x-filament::callout>
    </x-filament::section>

    {{-- 2. System abilities. --}}
    <x-filament::section>
        <x-slot name="heading">System permissions</x-slot>
        <x-slot name="description">Abilities that are not about one particular record.</x-slot>

        <div class="pm-system">
            @foreach($system as $key => $label)
                <label class="pm-check">
                    <x-filament::input.checkbox wire:model="granted.{{ $field($key) }}" />
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </x-filament::section>

    {{-- 3. The matrix. --}}
    <x-filament::section>
        <x-slot name="heading">Content permissions</x-slot>
        <x-slot name="description">What a role may do, and how far it reaches.</x-slot>

        <div class="pm-legend">
            @foreach($scopeHelp as $scope => $help)
                <div class="pm-legend-item">
                    <div class="pm-legend-term">{{ $scopeLabels[$scope] }}</div>
                    <div class="pm-legend-desc">{{ $help }}</div>
                </div>
            @endforeach
        </div>

        <div class="pm-table-wrap">
            <table class="pm-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Create</th>
                        <th>View</th>
                        <th>Edit</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entities as $entity => $meta)
                        <tr>
                            <td>
                                <div class="pm-type">{{ $meta['label'] }}</div>
                                <div class="pm-row-actions">
                                    <button type="button" class="pm-row-action"
                                            wire:click="toggleEntity('{{ $entity }}', true)">All on</button>
                                    <button type="button" class="pm-row-action pm-muted"
                                            wire:click="toggleEntity('{{ $entity }}', false)">Off</button>
                                </div>
                            </td>

                            <td>
                                {{-- Create has no scope: you either may raise one or you may not. --}}
                                <label class="pm-check">
                                    <x-filament::input.checkbox wire:model="granted.{{ $field($entity.'.create') }}" />
                                    <span>Allowed</span>
                                </label>
                            </td>

                            @foreach(['view', 'edit', 'delete'] as $action)
                                <td>
                                    <div class="pm-scopes">
                                        @foreach($meta['scopes'] as $scope)
                                            <label class="pm-check">
                                                <x-filament::input.checkbox
                                                    wire:model="granted.{{ $field($entity.'.'.$action.'.'.$scope) }}" />
                                                <span>{{ $scopeLabels[$scope] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-slot name="footer">
            <p style="font-size:.8125rem; color:var(--gray-500);">
                A project's manager keeps their say over that project's work whatever this
                matrix says — managing the project is what that role is for.
            </p>
        </x-slot>
    </x-filament::section>
</x-filament-panels::page>
