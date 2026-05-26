<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Define the rooms, floors, and areas for this project.
    </p>

    {{-- Add new area --}}
    <div class="flex gap-2">
        <input
            wire:model.live="newAreaName"
            wire:keydown.enter="addArea"
            type="text"
            placeholder="e.g. Ground Floor, Reception, Office 1..."
            class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
        />
        <button
            wire:click="addArea"
            class="flex items-center gap-1 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-medium text-gray-700 dark:text-gray-300"
        >
            <x-heroicon-o-plus class="w-4 h-4" /> Add
        </button>
    </div>

    @error('newAreaName')
    <p class="text-sm text-red-500">{{ $message }}</p>
    @enderror

    {{-- Area list --}}
    <ul class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
        @foreach($areas as $area)
        <li wire:key="modal-area-{{ $area->id }}" class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-gray-900">
            <x-heroicon-o-map-pin class="size-4 text-gray-400 shrink-0" />
            <span class="flex-1 text-sm text-gray-900 dark:text-white">{{ $area->name }}</span>
            <span class="text-xs text-gray-400">{{ $area->lines->count() }} {{ Str::plural('item', $area->lines->count()) }}</span>
            <button
                wire:click="removeArea({{ $area->id }})"
                wire:confirm="Remove '{{ $area->name }}'? All lines in this area will also be deleted."
                class="text-gray-400 hover:text-red-500 dark:hover:text-red-400 rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-800"
            >
                <x-heroicon-o-trash class="size-4" />
            </button>
        </li>
        @endforeach

        @if($areas->isEmpty())
        <li class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500 bg-white dark:bg-gray-900">
            No areas yet. Add one above.
        </li>
        @endif
    </ul>
</div>
