<div
    wire:key="customer-projects-list-{{ $this->pastProjectsAccountId }}"
    x-data="{ requested: false }"
    x-init="if (! requested) { requested = true; $wire.loadCustomerPastProjects(1) }"
    class="space-y-4"
>
    <p class="text-sm text-gray-500 dark:text-gray-400">Customer projects from Salesforce, newest first. Five projects per page.</p>

    <div wire:loading.flex wire:target="loadCustomerPastProjects" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite">
        <x-filament::loading-indicator class="h-5 w-5" />
        <span>Loading projects from Salesforce…</span>
    </div>

    <div wire:loading.remove wire:target="loadCustomerPastProjects" class="space-y-4">
        @if ($this->pastProjectsError)
            <div role="alert" class="flex items-center justify-between gap-3 text-sm text-danger-600 dark:text-danger-400">
                <span>{{ $this->pastProjectsError }}</span>
                <x-filament::button type="button" color="gray" size="sm" wire:click="loadCustomerPastProjects(1)">Retry</x-filament::button>
            </div>
        @elseif ($this->pastProjectsLoaded)
            @if ($this->pastProjects === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">No past projects found for this customer.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <tr>
                                <th class="px-3 py-2 font-semibold">Reference / Project</th>
                                <th class="px-3 py-2 font-semibold">Status</th>
                                <th class="px-3 py-2 font-semibold">Project Date</th>
                                <th class="px-3 py-2 font-semibold">LuxQuote</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->pastProjects as $project)
                                <tr>
                                    <td class="px-3 py-3"><div class="font-medium text-gray-950 dark:text-white">{{ $project['name'] }}</div><div class="text-xs text-gray-500 dark:text-gray-400">{{ $project['reference'] }}</div></td>
                                    <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $project['stage'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-gray-600 dark:text-gray-300">{{ $project['date'] }}</td>
                                    <td class="px-3 py-3">
                                        @if ($project['url'])
                                            <a href="{{ $project['url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Open in LuxQuote</a>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">Salesforce</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ ($this->pastProjectsPage - 1) * 5 + 1 }}–{{ min($this->pastProjectsPage * 5, $this->pastProjectsTotal) }} of {{ $this->pastProjectsTotal }} projects</span>
                    <div class="flex gap-2">
                        <x-filament::button type="button" color="gray" size="sm" wire:click="loadCustomerPastProjects({{ $this->pastProjectsPage - 1 }})" :disabled="$this->pastProjectsPage <= 1">Previous</x-filament::button>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="loadCustomerPastProjects({{ $this->pastProjectsPage + 1 }})" :disabled="$this->pastProjectsPage * 5 >= $this->pastProjectsTotal">Next</x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
