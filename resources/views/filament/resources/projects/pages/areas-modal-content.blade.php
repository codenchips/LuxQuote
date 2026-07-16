<div class="space-y-4" x-data="{ confirmDeleteAreaId: null, confirmDeleteAreaName: '' }">
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
            type="button"
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
            <input
                type="text"
                value="{{ $area->name }}"
                maxlength="255"
                wire:change="renameArea({{ $area->id }}, $event.target.value)"
                x-on:keydown.enter="$el.blur()"
                aria-label="Rename {{ $area->name }}"
                class="min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm text-gray-900 hover:border-gray-300 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:text-white dark:hover:border-gray-600"
            />
            <span class="text-xs text-gray-400">{{ $area->lines->count() }} {{ Str::plural('item', $area->lines->count()) }}</span>
            <button
                type="button"
                @click.stop="confirmDeleteAreaId = {{ $area->id }}; confirmDeleteAreaName = '{{ addslashes($area->name) }}'"
                title="Delete area"
                class="text-gray-400 hover:text-red-500 dark:hover:text-red-400 rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-800"
            >
                <x-heroicon-o-trash class="size-4" />
            </button>
            <button
                type="button"
                wire:click.stop="copyArea({{ $area->id }})"
                title="Copy area"
                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
            >
                <x-heroicon-o-document-duplicate class="size-4" />
            </button>
        </li>
        @endforeach

        @if($areas->isEmpty())
        <li class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500 bg-white dark:bg-gray-900">
            No areas yet. Add one above.
        </li>
        @endif
    </ul>

    {{-- Delete Area Confirmation Modal --}}
    <div
        x-show="confirmDeleteAreaId !== null"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:keydown.escape.window="confirmDeleteAreaId = null"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
        style="display: none"
    >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="confirmDeleteAreaId = null"></div>
        <div class="relative z-10 w-full max-w-sm bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700 p-6">
            <div class="flex items-start gap-4 mb-5">
                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30">
                    <x-heroicon-o-trash class="w-5 h-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete this area?</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        All lines in <span class="font-medium" x-text="confirmDeleteAreaName"></span> will also be permanently deleted.
                    </p>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button
                    type="button"
                    @click="confirmDeleteAreaId = null"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="$wire.removeArea(confirmDeleteAreaId); confirmDeleteAreaId = null"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>
