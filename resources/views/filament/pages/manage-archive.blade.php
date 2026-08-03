<x-filament-panels::page>
    @php
        $archived = \App\Models\Task::archived()->count();
        $due = \App\Support\Archive::due()->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-center gap-8">
        <div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">In the archive</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $archived }}</div>
        </div>
        <div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Waiting for the next sweep</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $due }}</div>
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
        Archiving is not deleting. An archived task keeps its discussion, files and
        links, still opens from any link to it, and still counts in reports — it
        just stops taking up room on the boards. Reopening a task takes it out of
        the archive on its own.
    </p>
</x-filament-panels::page>
