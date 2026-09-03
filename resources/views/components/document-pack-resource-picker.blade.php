@props([
    'project',
    'resources',
    'totalRows',
    'totalPages',
    'currentPage',
    'previewResource' => null,
])

<x-filament::modal
    id="document-pack-resource-picker"
    width="5xl"
    :autofocus="false"
    heading="Select Resource"
    description="Choose a PDF from the Resources library to add to this document pack."
>
    <div class="space-y-4">
        <div class="relative max-w-md">
            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                wire:model.live.debounce.300ms="documentPackResourceSearch"
                type="search"
                placeholder="Search PDF resources"
                class="h-10 w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-white"
            />
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="w-32 px-4 py-3 font-semibold">File Type</th>
                            <th class="px-4 py-3 font-semibold">Display Name</th>
                            <th class="w-44 px-4 py-3 font-semibold">Date Added</th>
                            <th class="w-40 px-4 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($resources as $resource)
                            <tr wire:key="document-pack-resource-{{ $resource->id }}" class="text-gray-700 dark:text-gray-200">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                        <x-heroicon-o-document-text class="h-4 w-4" />
                                        PDF
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-medium" title="{{ $resource->original_filename }}">
                                    {{ $resource->display_name }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-gray-400">
                                    {{ $resource->created_at->format('M d Y H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            size="sm"
                                            icon="heroicon-o-eye"
                                            wire:click="previewDocumentPackResource({{ $resource->id }})"
                                        >
                                            Preview
                                        </x-filament::button>
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            icon="heroicon-o-plus"
                                            wire:click="addDocumentPackResource({{ $resource->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="addDocumentPackResource({{ $resource->id }})"
                                        >
                                            Add
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    {{ $this->documentPackResourceSearch ? 'No matching PDF resources found.' : 'No PDF resources are available.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($totalRows > 0)
            <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                <span>{{ $totalRows }} {{ \Illuminate\Support\Str::plural('resource', $totalRows) }}</span>
                <div class="flex items-center gap-3">
                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        wire:click="previousDocumentPackResourcePage"
                        :disabled="$currentPage <= 1"
                    >
                        Previous
                    </x-filament::button>
                    <span>Page {{ $currentPage }} of {{ $totalPages }}</span>
                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        wire:click="nextDocumentPackResourcePage"
                        :disabled="$currentPage >= $totalPages"
                    >
                        Next
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>
</x-filament::modal>

<x-filament::modal
    id="document-pack-resource-preview"
    width="screen-xl"
    :autofocus="false"
    :heading="$previewResource?->display_name ?? 'Resource preview'"
    :description="$previewResource?->original_filename"
>
    @if($previewResource)
        <div class="h-[72vh] overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10">
            <iframe
                class="h-full w-full border-0 bg-white"
                src="{{ route('projects.document-packs.resources.file', ['project' => $project, 'resourceFile' => $previewResource]) }}"
                title="Preview of {{ $previewResource->display_name }}"
            ></iframe>
        </div>
    @else
        <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
            This Resource is no longer available.
        </div>
    @endif
</x-filament::modal>
