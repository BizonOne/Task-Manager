<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap items-center gap-8">
        <div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Current logo</div>
            @if(\App\Support\Brand::logoUrl())
                <img src="{{ \App\Support\Brand::logoUrl() }}" alt="Logo" style="height:48px; width:auto;">
            @else
                <span class="text-sm text-gray-400">Using the default logo</span>
            @endif
        </div>
        <div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Current favicon</div>
            @if(\App\Support\Brand::faviconUrl())
                <img src="{{ \App\Support\Brand::faviconUrl() }}" alt="Favicon" style="height:32px; width:32px;">
            @else
                <span class="text-sm text-gray-400">Using the default favicon</span>
            @endif
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
