@props([
    'templates',
    'visibilityOptions',
])

<x-filament::modal
    id="save-document-pack-template"
    width="lg"
    :autofocus="false"
    heading="Save as Template"
    description="Save these documents and their order for use in other projects."
>
    <div class="space-y-5">
        <label class="block">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Template name</span>
            <input
                type="text"
                wire:model="documentPackTemplateName"
                maxlength="120"
                class="mt-1 block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            />
            @error('documentPackTemplateName')
                <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>
            @enderror
        </label>

        <label class="block">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Visibility</span>
            <select
                wire:model="documentPackTemplateVisibilityTarget"
                class="mt-1 block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            >
                @foreach($visibilityOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('documentPackTemplateVisibilityTarget')
                <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>
            @enderror
            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                Open templates are available to everyone. Private templates are visible only to you; Team templates are shared with that team.
            </span>
        </label>

        <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
            <x-filament::button
                type="button"
                color="gray"
                x-on:click="$dispatch('close-modal', { id: 'save-document-pack-template' })"
            >
                Cancel
            </x-filament::button>
            <x-filament::button
                type="button"
                wire:click="saveDocumentPackAsTemplate"
                wire:loading.attr="disabled"
                wire:target="saveDocumentPackAsTemplate"
            >
                OK
            </x-filament::button>
        </div>
    </div>
</x-filament::modal>

<x-filament::modal
    id="select-document-pack-template"
    width="4xl"
    :autofocus="false"
    heading="Select a template"
    description="Choose a document order to use as the starting point for this project pack."
>
    <div class="space-y-5">
        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="w-14 px-4 py-3 font-semibold"><span class="sr-only">Select</span></th>
                            <th class="px-4 py-3 font-semibold">Template</th>
                            <th class="w-44 px-4 py-3 font-semibold">Visibility</th>
                            <th class="w-44 px-4 py-3 font-semibold">Owner</th>
                            <th class="w-28 px-4 py-3 text-right font-semibold">Documents</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($templates as $template)
                            <tr wire:key="document-pack-template-{{ $template->id }}" class="text-gray-700 dark:text-gray-200">
                                <td class="px-4 py-3">
                                    <input
                                        type="radio"
                                        name="document-pack-template"
                                        value="{{ $template->id }}"
                                        wire:model.live="selectedDocumentPackTemplateId"
                                        class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                        aria-label="Select {{ $template->name }}"
                                    />
                                </td>
                                <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">{{ $template->name }}</td>
                                <td class="px-4 py-3">
                                    @if($template->visibility === \App\Enums\ProjectVisibility::Team)
                                        Team: {{ $template->team?->name ?? 'Unavailable team' }}
                                    @else
                                        {{ $template->visibility->label() }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $template->owner?->name ?? 'Former user' }}</td>
                                <td class="px-4 py-3 text-right">{{ $template->items_count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    No document pack templates are available yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
            <x-filament::button
                type="button"
                color="gray"
                x-on:click="$dispatch('close-modal', { id: 'select-document-pack-template' })"
            >
                Cancel
            </x-filament::button>
            <x-filament::button
                type="button"
                wire:click="useSelectedDocumentPackTemplate"
                wire:loading.attr="disabled"
                wire:target="useSelectedDocumentPackTemplate"
                :disabled="$this->selectedDocumentPackTemplateId === null"
            >
                Use this template
            </x-filament::button>
        </div>
    </div>
</x-filament::modal>
