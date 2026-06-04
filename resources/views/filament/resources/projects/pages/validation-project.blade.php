<x-filament-panels::page>
    @php
        $issues = $this->validationIssues;
        $unresolvedCount = collect($issues)->where('approved', false)->count();
        $isValidated = $this->activeRevisionValidated;
    @endphp

    <div class="space-y-6">
        <div class="flex justify-end">
            <x-filament::button
                wire:click="runValidation"
                wire:loading.attr="disabled"
                icon="heroicon-o-arrow-path"
            >
                <span wire:loading.remove wire:target="runValidation">Run Validation</span>
                <span wire:loading wire:target="runValidation">Running Validation...</span>
            </x-filament::button>
        </div>

        <div
            @class([
                'rounded-xl border px-5 py-4',
                'border-red-300 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100' => $unresolvedCount,
                'border-green-300 bg-green-50 text-green-950 dark:border-green-800 dark:bg-green-950/30 dark:text-green-100' => ! $unresolvedCount,
            ])
        >
            <div class="flex items-center gap-3">
                @if($unresolvedCount)
                    <x-heroicon-o-exclamation-circle class="h-7 w-7 shrink-0 text-red-500" />
                @else
                    <x-heroicon-o-check-circle class="h-7 w-7 shrink-0 text-green-500" />
                @endif

                <div>
                    <p class="font-semibold">
                        {{ $unresolvedCount ? $unresolvedCount.' unresolved '.Str::plural('issue', $unresolvedCount) : ($isValidated ? 'Revision validated' : 'No unresolved issues') }}
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $unresolvedCount ? 'Resolve or approve each warning before proceeding.' : ($isValidated ? 'This revision is locked against further editing.' : 'Run validation to validate and lock this revision.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400">
                Validation issues ({{ count($issues) }})
            </div>

            @forelse($issues as $issue)
                <div
                    wire:key="{{ $issue['key'] }}"
                    class="border-b border-gray-200 px-5 py-4 last:border-b-0 dark:border-gray-700"
                >
                    <div class="flex items-start gap-4">
                        <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span class="font-mono font-medium text-gray-950 dark:text-white">{{ $issue['code'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $issue['description'] }}</span>
                                <span
                                    @class([
                                        'rounded-md px-2 py-0.5 text-xs font-medium',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $issue['approved'],
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => ! $issue['approved'],
                                    ])
                                >
                                    {{ $issue['approved'] ? 'Approved' : 'Warning' }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Area: {{ $issue['area'] }}</span>
                            </div>

                            <p class="mt-2 text-sm text-gray-950 dark:text-white">{{ $issue['message'] }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if($issue['approved'])
                                <x-filament::button
                                    wire:click="undoIssueApproval({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    color="gray"
                                    size="sm"
                                >
                                    Undo
                                </x-filament::button>
                            @else
                                @if($issue['type'] === 'duplicate_sku')
                                    <x-filament::button
                                        wire:click="mergeIssue({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-arrows-pointing-in"
                                    >
                                        Merge
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    wire:click="approveIssue({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    size="sm"
                                    icon="heroicon-o-hand-thumb-up"
                                >
                                    Approve
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-5 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    No validation issues to review.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
