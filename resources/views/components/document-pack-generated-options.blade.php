@props([
    'areas',
    'revisionLabel',
    'roleLabel',
])

<x-filament::modal
    id="document-pack-generated-options"
    width="2xl"
    :autofocus="false"
    :heading="'Choose '.$roleLabel.' options'"
    :description="'Select the content to generate from '.($revisionLabel ?? 'the chosen revision').'.'"
>
    <div class="space-y-5">
        <div>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Areas</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">These names will be matched again if you generate from another revision or reuse a template.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="selectAllDocumentPackOptionAreas"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        Select all
                    </button>
                    <button
                        type="button"
                        wire:click="$set('documentPackOptionAreaIds', [])"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        Clear
                    </button>
                </div>
            </div>

            <div class="max-h-72 divide-y divide-gray-200 overflow-y-auto rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @forelse($areas as $area)
                    <label wire:key="document-pack-option-area-{{ $area['id'] }}" class="grid cursor-pointer grid-cols-[auto_1fr_auto] items-center gap-4 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                        <input
                            type="checkbox"
                            value="{{ $area['id'] }}"
                            wire:model="documentPackOptionAreaIds"
                            class="h-5 w-5 rounded border-gray-300 text-orange-600 focus:ring-orange-500 dark:border-white/20 dark:bg-white/10"
                        />
                        <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $area['name'] }}</span>
                        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $area['items'] }} items · qty {{ $area['qty'] }}</span>
                    </label>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">This revision has no areas available.</p>
                @endforelse
            </div>

            @error('documentPackOptionAreaIds')
                <span class="mt-2 block text-xs text-danger-600">{{ $message }}</span>
            @enderror
        </div>

        <label class="flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
            <div>
                <span class="text-sm font-semibold text-gray-950 dark:text-white">Include datasheets</span>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Embed the product datasheets for the selected areas in this document.</p>
            </div>
            <input
                type="checkbox"
                wire:model="documentPackOptionIncludeDatasheets"
                class="h-5 w-5 rounded border-gray-300 text-orange-600 focus:ring-orange-500 dark:border-white/20 dark:bg-white/10"
            />
        </label>

        <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
            <x-filament::button
                type="button"
                color="gray"
                x-on:click="$dispatch('close-modal', { id: 'document-pack-generated-options' })"
            >
                Cancel
            </x-filament::button>
            <x-filament::button
                type="button"
                wire:click="saveDocumentPackGeneratedOptions"
                wire:loading.attr="disabled"
                wire:target="saveDocumentPackGeneratedOptions"
                :disabled="count($areas) === 0"
            >
                Apply options
            </x-filament::button>
        </div>
    </div>
</x-filament::modal>
