<x-filament-panels::page>
    @php
        $entities = \App\Support\Permissions::ENTITIES;
        $system = \App\Support\Permissions::SYSTEM;
        $scopeLabels = \App\Support\Permissions::SCOPE_LABELS;
        $scopeHelp = \App\Support\Permissions::SCOPE_HELP;
    @endphp

    <div class="mb-6">
        <label for="roleId" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Role</label>
        <select id="roleId" wire:model.live="roleId"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm">
            @foreach($this->editableRoles() as $role)
                <option value="{{ $role->id }}">{{ $role->name }}</option>
            @endforeach
        </select>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            <strong>super_admin</strong> is not listed: it bypasses every check by design, so a
            screen of ticked boxes for it would be a lie you could untick.
        </p>
    </div>

    {{-- 1. The thing that caused the confusion in the first place. --}}
    <section class="mb-8 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Admin panel permissions</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            The permission list under <em>Roles</em> governs <strong>this admin panel only</strong> —
            which resources a role may open here. It has never controlled the task
            manager itself. What follows on this page does.
        </p>
    </section>

    {{-- 2. System abilities. --}}
    <section class="mb-8">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">System permissions</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
            Abilities that are not about one particular record.
        </p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach($system as $key => $label)
                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="granted.{{ \App\Filament\Pages\ManagePermissions::field($key) }}"
                           class="mt-0.5 rounded border-gray-300 dark:border-gray-600">
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </section>

    {{-- 3. The matrix. --}}
    <section>
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">Content permissions</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
            What a role may do, and how far it reaches.
        </p>
        <ul class="mb-4 text-sm text-gray-500 dark:text-gray-400 space-y-0.5">
            @foreach($scopeHelp as $scope => $help)
                <li><strong>{{ $scopeLabels[$scope] }}</strong> — {{ $help }}</li>
            @endforeach
        </ul>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Type</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Create</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">View</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Edit</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Delete</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entities as $entity => $meta)
                        <tr class="border-t border-gray-200 dark:border-gray-700 align-top">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $meta['label'] }}</div>
                                <div class="mt-1 flex gap-2 text-xs">
                                    <button type="button" wire:click="toggleEntity('{{ $entity }}', true)"
                                            class="text-primary-600 hover:underline">All on</button>
                                    <button type="button" wire:click="toggleEntity('{{ $entity }}', false)"
                                            class="text-gray-400 hover:underline">Off</button>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                {{-- Create has no scope: you either may raise one or you may not. --}}
                                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model="granted.{{ \App\Filament\Pages\ManagePermissions::field($entity.'.create') }}"
                                           class="rounded border-gray-300 dark:border-gray-600">
                                    <span>Allowed</span>
                                </label>
                            </td>

                            @foreach(['view', 'edit', 'delete'] as $action)
                                <td class="px-4 py-3">
                                    <div class="space-y-1.5">
                                        @foreach($meta['scopes'] as $scope)
                                            <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                                <input type="checkbox"
                                                       wire:model="granted.{{ \App\Filament\Pages\ManagePermissions::field($entity.'.'.$action.'.'.$scope) }}"
                                                       class="rounded border-gray-300 dark:border-gray-600">
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

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            A project's manager keeps their say over that project's work whatever this
            matrix says — managing the project is what that role is for.
        </p>
    </section>
</x-filament-panels::page>
