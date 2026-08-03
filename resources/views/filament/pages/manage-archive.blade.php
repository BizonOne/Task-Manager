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

    @php
        $alive = \App\Support\Heartbeat::isAlive();
        $lastRun = \App\Support\Heartbeat::lastRunAt();
    @endphp

    @unless($alive)
        {{-- The sweep runs on a timer. If nothing drives the timer, it never
             runs, and there is no error anywhere to tell you so. --}}
        <div class="mb-6 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-950">
            <div class="font-semibold text-warning-800 dark:text-warning-200">
                The scheduler is not running
            </div>
            <div class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                Nothing has driven the task scheduler
                {{ $lastRun ? 'since '.\App\Support\Dates::dateTime($lastRun) : 'yet' }},
                so the nightly sweep will not happen on its own. Use
                <strong>Run the sweep now</strong> above, or have the host run
                <code>php artisan schedule:run</code> every minute.
            </div>
        </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    @if($alive)
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            Scheduler last checked in {{ \App\Support\Dates::dateTime($lastRun) }} — the nightly sweep is running.
        </p>
    @endif

    <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
        Archiving is not deleting. An archived task keeps its discussion, files and
        links, still opens from any link to it, and still counts in reports — it
        just stops taking up room on the boards. Reopening a task takes it out of
        the archive on its own.
    </p>
</x-filament-panels::page>
