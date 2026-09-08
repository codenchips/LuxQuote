<x-filament-panels::page>
    @php
        $issues = collect($this->validationIssues)->where('approved', false)->values();
        $validatedLines = $this->validatedLines;
        $unresolvedCount = $issues->count();
        $isValidated = $this->activeRevisionValidated;
        $isApproved = $this->activeRevisionApproved;
        $isReadyToApprove = $this->activeRevisionReadyForApproval;
        $canViewPrices = $this->canViewPrices();
        $projectHasCover = $this->projectHasCover();
        $canEditPrices = $this->canEditPrices();
        $canEditCover = $this->canEditCover();
        $canUpdateValidationLines = $this->canUpdateValidationLines();
        $canFlagValidationLines = $this->canFlagValidationLines();
        $canMergeValidationLines = $this->canMergeValidationLines();
        $canApproveValidationLines = $this->canApproveValidationLines();
        $comparisonOptions = $this->revisionComparisonOptions;
        $selectedComparisonIds = collect($selectedComparisonRevisionIds)->map(fn ($id) => (int) $id)->unique()->values();
        $comparison = $revisionComparisonModalOpen ? $this->revisionComparison : [];
        $validatedLineGridColumns = $canViewPrices && $projectHasCover
            ? '130px 1fr 70px 95px 210px 95px 1.4fr 110px'
            : ($canViewPrices
                ? '130px 1fr 70px 95px 95px 1.4fr 110px'
                : '130px 1fr 70px 95px 1.4fr 110px');
    @endphp

    <div class="space-y-6">
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
                        {{ $isApproved ? 'Project is approved and locked' : ($unresolvedCount ? $unresolvedCount.' unresolved '.Str::plural('issue', $unresolvedCount) : ($isReadyToApprove ? 'Ready to approve' : 'No unresolved issues')) }}
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $isApproved ? 'This revision is locked against further editing.' : ($unresolvedCount ? 'Resolve or approve each warning before proceeding.' : ($isReadyToApprove ? 'Click Approve Revision to lock this revision.' : 'Run validation to check this revision.')) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400">
                Issues ({{ count($issues) }})
            </div>

            @forelse($issues as $issue)
                <div
                    wire:key="{{ $issue['key'] }}"
                    class="border-b border-gray-200 px-5 py-4 last:border-b-0 dark:border-gray-700"
                >
                    <div class="flex items-center gap-4">
                        <x-dynamic-component
                            :component="$this->validationIssueIcon($issue)"
                            class="mt-0.5 h-5 w-5 shrink-0 {{ $this->validationIssueIconClasses($issue) }}"
                        />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                @if(filled($issue['description']))
                                    <span class="text-gray-500 dark:text-gray-400">{{ $issue['description'] }}</span>
                                @endif
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium {{ $this->validationIssueBadgeClasses($issue) }}">
                                    {{ $this->validationIssueLabel($issue) }}
                                </span>
                                @if($issue['flagged'])
                                    <span class="rounded-md bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                        Issue flagged
                                    </span>
                                @endif
                            </div>

                            <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{!! $this->validationIssueMessage($issue) !!}</p>
                            @if(filled($issue['flag_note'] ?? null) && $issue['type'] !== 'manual_flag')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-300">
                                    Flag note: {{ $issue['flag_note'] }}
                                </p>
                            @endif

                        </div>

                        @if($issue['type'] === 'price_mismatch' && $canViewPrices)
                            <div class="flex w-[38rem] shrink-0 items-end justify-end gap-2 self-center text-sm">
                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">RRP</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm text-gray-500 dark:text-gray-400">
                                        {{ $issue['rrp'] !== null ? $this->record->formatCurrency((float) $issue['rrp']) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Quote</span>
                                    @if(! $issue['approved'] && $canEditPrices && $canUpdateValidationLines)
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="99999999.99"
                                            value="{{ $issue['quote_price'] }}"
                                            x-on:blur="
                                                const value = $el.value === '' ? '' : Math.min(99999999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                                $el.value = value;
                                                $wire.updateIssueQuotePrice({{ \Illuminate\Support\Js::from($issue['key']) }}, value);
                                            "
                                            class="h-[34px] w-full rounded-lg border border-gray-300 bg-white px-2 py-0 text-left text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                    @else
                                        <span class="block h-[34px] px-1 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">
                                            {{ ($issue['quote_price'] ?? null) !== null ? $this->record->formatCurrency((float) $issue['quote_price']) : '—' }}
                                        </span>
                                    @endif
                                </label>
                            </div>
                        @endif

                        @if($issue['type'] === 'cover_mismatch' && $canViewPrices)
                            <div class="flex w-[38rem] shrink-0 items-end justify-end gap-2 self-center text-sm">
                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">RRP</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm text-gray-500 dark:text-gray-400">
                                        {{ ($issue['rrp'] ?? null) !== null ? $this->record->formatCurrency((float) $issue['rrp']) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Net</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        {{ ($issue['net_price'] ?? null) !== null ? $this->record->formatCurrency((float) $issue['net_price']) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        {{ ($issue['total_price'] ?? null) !== null ? $this->record->formatCurrency((float) $issue['total_price']) : '—' }}
                                    </span>
                                </label>

                                @foreach(['cover_1' => 'C1', 'cover_2' => 'C2', 'cover_3' => 'C3'] as $coverField => $coverLabel)
                                    <label class="w-20 space-y-1">
                                        <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ $coverLabel }} {{ ($issue['cover_defaults'][$coverField] ?? null) !== null ? number_format((float) $issue['cover_defaults'][$coverField], 2) : '—' }}%
                                        </span>
                                        @if(! $issue['approved'] && $canEditCover && $canUpdateValidationLines)
                                            <span class="relative block">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    max="999.99"
                                                    value="{{ ($issue['cover_values'][$coverField] ?? null) !== null ? number_format((float) $issue['cover_values'][$coverField], 2, '.', '') : '' }}"
                                                    x-on:blur="
                                                        const value = $el.value === '' ? '' : Math.min(999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                                        $el.value = value;
                                                        $wire.updateIssueCoverValue({{ \Illuminate\Support\Js::from($issue['key']) }}, '{{ $coverField }}', value);
                                                    "
                                                    placeholder="{{ $coverLabel }}"
                                                    class="h-[34px] w-full rounded-lg border border-gray-300 bg-white px-1.5 py-0 pr-4 text-right text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                />
                                                <span class="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
                                            </span>
                                        @else
                                            <span class="block h-[34px] px-1 py-2 text-right text-sm font-semibold text-gray-700 dark:text-gray-200">
                                                {{ ($issue['cover_values'][$coverField] ?? null) !== null ? number_format((float) $issue['cover_values'][$coverField], 2).'%' : '—' }}
                                            </span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        <div
                            @class([
                                'flex w-80 shrink-0 items-center justify-end gap-2',
                                'self-start pt-5' => in_array($issue['type'], ['price_mismatch', 'cover_mismatch'], true),
                            ])
                        >
                            @if($issue['type'] === 'price_mismatch' && $canViewPrices && $canEditPrices && $canUpdateValidationLines && ($issue['rrp'] ?? null) !== null && (float) $issue['rrp'] > 0)
                                <x-filament::button
                                    wire:click="matchIssueQuotePrice({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-o-arrows-right-left"
                                    class="h-[34px] min-h-[34px] whitespace-nowrap"
                                >
                                    Match
                                </x-filament::button>
                            @endif

                            @if($issue['type'] === 'duplicate_sku' && $canMergeValidationLines)
                                <x-filament::button
                                    wire:click="mergeIssue({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-o-arrows-pointing-in"
                                    class="h-[34px] min-h-[34px] whitespace-nowrap"
                                >
                                    Merge
                                </x-filament::button>
                            @endif

                            @if($canApproveValidationLines)
                                <x-filament::button
                                    wire:click="approveIssue({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    size="sm"
                                    icon="heroicon-o-hand-thumb-up"
                                    class="h-[34px] min-h-[34px] whitespace-nowrap"
                                >
                                    Approve
                                </x-filament::button>
                            @endif

                            @if($canFlagValidationLines)
                                <x-filament::button
                                    wire:click="openFlagIssueModal({{ \Illuminate\Support\Js::from($issue['key']) }})"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-o-flag"
                                    :disabled="$issue['flagged']"
                                    class="h-[34px] min-h-[34px] w-[34px] min-w-[34px] justify-center border-red-500/70 px-0 text-red-500 hover:border-red-400 hover:bg-red-500/10 hover:text-red-400 disabled:cursor-not-allowed disabled:border-red-500/30 disabled:text-red-500/40 disabled:hover:bg-transparent dark:border-red-500/60 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300 dark:disabled:border-red-500/25 dark:disabled:text-red-400/35"
                                    aria-label="Flag issue"
                                >
                                    <span class="sr-only">Flag issue</span>
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

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400">
                Validated ({{ count($validatedLines) }})
            </div>

            <div class="grid gap-3 border-b border-gray-100 bg-gray-50 px-5 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-800/40 dark:text-gray-400" style="grid-template-columns: {{ $validatedLineGridColumns }}">
                <div>SKU</div>
                <div>Description</div>
                <div class="text-center">Qty</div>
                @if($canViewPrices)
                    <div class="text-right">Quote</div>
                @endif
                @if($canViewPrices && $projectHasCover)
                    <div>Cover</div>
                @endif
                <div>Status</div>
                <div>Validation note</div>
                <div></div>
            </div>

            @forelse($validatedLines as $line)
                <div
                    wire:key="validated-line-{{ $line['id'] }}"
                    class="grid items-center gap-3 border-b border-gray-100 px-5 py-3 text-sm last:border-b-0 dark:border-gray-800"
                    style="grid-template-columns: {{ $validatedLineGridColumns }}"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-green-500" />
                        <span class="truncate font-mono font-medium text-gray-950 dark:text-white">{{ $line['code'] ?: '—' }}</span>
                    </div>
                    <div class="truncate text-gray-600 dark:text-gray-300">{{ $line['description'] }}</div>
                    <div class="text-center text-gray-600 dark:text-gray-300">{{ $line['qty'] }}</div>
                    @if($canViewPrices)
                    <div class="text-right text-gray-600 dark:text-gray-300">
                        {{ $line['unit_price'] !== null ? $this->record->formatCurrency((float) $line['unit_price']) : '—' }}
                    </div>
                    @endif
                    @if($canViewPrices && $projectHasCover)
                    <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                        @foreach(['cover_1' => 'C1', 'cover_2' => 'C2', 'cover_3' => 'C3'] as $coverField => $coverLabel)
                            <span class="whitespace-nowrap">
                                {{ $line[$coverField] !== null ? number_format((float) $line[$coverField], 2).'%' : '—' }}
                            </span>
                        @endforeach
                    </div>
                    @endif
                    <div>
                        <span
                            @class([
                                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
                                'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $line['status'] === 'Approved',
                                'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' => $line['status'] !== 'Approved',
                            ])
                        >
                            {{ $line['status'] }}
                        </span>
                    </div>
                    <div class="text-gray-500 dark:text-gray-400">{{ $line['note'] }}</div>
                    <div class="flex justify-end">
                        @if(! $isApproved && $canFlagValidationLines)
                        <x-filament::button
                            wire:click="openFlagValidatedLineModal({{ $line['id'] }})"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-flag"
                            class="h-[34px] min-h-[34px] w-[34px] min-w-[34px] justify-center border-red-500/70 px-0 text-red-500 hover:border-red-400 hover:bg-red-500/10 hover:text-red-400 dark:border-red-500/60 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                            aria-label="Flag issue"
                        >
                            <span class="sr-only">Flag issue</span>
                        </x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-5 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    No validated product lines yet.
                </div>
            @endforelse
        </div>

        @if($revisionCompareSelectionModalOpen)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeRevisionCompareSelectionModal()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="revision-compare-selection-title"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
        >
            <div
                class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                wire:click="closeRevisionCompareSelectionModal"
            ></div>

            <div class="relative z-10 w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <div>
                        <h2 id="revision-compare-selection-title" class="text-base font-semibold text-gray-900 dark:text-white">Compare revisions</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Select exactly two revisions. The older revision will be shown first.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeRevisionCompareSelectionModal"
                        class="rounded-md p-1 text-gray-400 transition hover:text-gray-700 dark:hover:text-gray-200"
                        aria-label="Close revision selection"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[55vh] divide-y divide-gray-200 overflow-y-auto px-6 py-2 dark:divide-gray-700">
                    @foreach($comparisonOptions as $revisionOption)
                        @php
                            $revisionSelected = $selectedComparisonIds->contains($revisionOption['id']);
                            $selectionLimitReached = $selectedComparisonIds->count() >= 2 && ! $revisionSelected;
                        @endphp
                        <label class="flex items-center gap-4 py-4 {{ $selectionLimitReached ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}">
                            <input
                                type="checkbox"
                                wire:model.live="selectedComparisonRevisionIds"
                                value="{{ $revisionOption['id'] }}"
                                @disabled($selectionLimitReached)
                                class="h-5 w-5 rounded border-gray-300 text-orange-600 focus:ring-orange-500 dark:border-gray-600 dark:bg-gray-800"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ $revisionOption['label'] }}</span>
                                    <span class="rounded-md border border-gray-300 px-2 py-0.5 text-xs text-gray-600 dark:border-gray-600 dark:text-gray-300">{{ $revisionOption['status'] }}</span>
                                    @if($revisionOption['active'])
                                        <span class="rounded-md bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-800 dark:bg-orange-500/15 dark:text-orange-300">Active</span>
                                    @endif
                                </span>
                                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Created {{ $revisionOption['created_at'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $selectedComparisonIds->count() }} of 2 selected</span>
                    <div class="flex items-center gap-3">
                        <x-filament::button wire:click="closeRevisionCompareSelectionModal" color="gray">
                            Cancel
                        </x-filament::button>
                        <x-filament::button
                            wire:click="compareSelectedRevisions"
                            wire:loading.attr="disabled"
                            wire:target="compareSelectedRevisions"
                            :disabled="$selectedComparisonIds->count() !== 2"
                        >
                            Compare revisions
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($revisionComparisonModalOpen && $comparison !== [])
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeRevisionComparisonModal()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="revision-comparison-title"
            class="fixed inset-0 z-[10000] flex items-center justify-center p-3 sm:p-6"
        >
            <div
                class="absolute inset-0 bg-black/75 backdrop-blur-sm"
                wire:click="closeRevisionComparisonModal"
            ></div>

            <div class="relative z-10 flex h-[92vh] w-full max-w-[96rem] flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-[#0d1117] dark:ring-gray-700">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <div>
                        <h2 id="revision-comparison-title" class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $comparison['from']['label'] }} <span class="text-gray-400">→</span> {{ $comparison['to']['label'] }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Read-only revision comparison · red was removed or replaced · green was added</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeRevisionComparisonModal"
                        class="rounded-md p-1 text-gray-400 transition hover:text-gray-700 dark:hover:text-gray-200"
                        aria-label="Close revision comparison"
                    >
                        <x-heroicon-o-x-mark class="h-6 w-6" />
                    </button>
                </div>

                <div class="flex flex-wrap gap-x-5 gap-y-2 border-b border-gray-200 bg-gray-50 px-6 py-3 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                    <span class="text-green-700 dark:text-green-400">+{{ $comparison['summary']['areas_added'] }} areas</span>
                    <span class="text-red-700 dark:text-red-400">−{{ $comparison['summary']['areas_removed'] }} areas</span>
                    <span>{{ $comparison['summary']['areas_changed'] }} areas changed</span>
                    <span class="text-green-700 dark:text-green-400">+{{ $comparison['summary']['lines_added'] }} lines</span>
                    <span class="text-red-700 dark:text-red-400">−{{ $comparison['summary']['lines_removed'] }} lines</span>
                    <span>{{ $comparison['summary']['lines_changed'] }} lines changed</span>
                </div>

                <div class="flex-1 overflow-auto p-4 font-mono text-[13px] leading-6 sm:p-6">
                    @if(collect($comparison['summary'])->sum() === 0)
                        <div class="rounded-lg border border-gray-200 px-5 py-12 text-center font-sans text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            These revisions contain no differences.
                        </div>
                    @endif

                    <div class="space-y-5">
                        @foreach($comparison['areas'] as $areaIndex => $areaDiff)
                            @php
                                $areaName = $areaDiff['to_name'] ?? $areaDiff['from_name'] ?? 'Untitled area';
                                $areaTone = match($areaDiff['status']) {
                                    'added' => 'border-green-300 dark:border-green-800',
                                    'removed' => 'border-red-300 dark:border-red-800',
                                    'changed' => 'border-amber-300 dark:border-amber-800',
                                    default => 'border-gray-200 dark:border-gray-700',
                                };
                            @endphp
                            <section wire:key="comparison-area-{{ $areaIndex }}" class="overflow-hidden rounded-lg border {{ $areaTone }}">
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-100 px-4 py-2 dark:border-gray-700 dark:bg-[#161b22]">
                                    <span class="font-semibold text-purple-700 dark:text-purple-300">@@ Area: {{ $areaName }} @@</span>
                                    @if($areaDiff['status'] !== 'unchanged')
                                        <span class="font-sans text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $areaDiff['status'] }}</span>
                                    @endif
                                </div>

                                @if($areaDiff['status'] === 'changed' && ($areaDiff['from_name'] !== $areaDiff['to_name'] || $areaDiff['from_position'] !== $areaDiff['to_position']))
                                    @if($areaDiff['from_name'] !== $areaDiff['to_name'])
                                        <div class="grid grid-cols-[1.25rem_7rem_1fr] bg-red-50 px-4 text-red-900 dark:bg-red-950/35 dark:text-red-200"><span>-</span><span>Area name</span><span>{{ $areaDiff['from_name'] }}</span></div>
                                        <div class="grid grid-cols-[1.25rem_7rem_1fr] bg-green-50 px-4 text-green-900 dark:bg-green-950/35 dark:text-green-200"><span>+</span><span>Area name</span><span>{{ $areaDiff['to_name'] }}</span></div>
                                    @endif
                                    @if($areaDiff['from_position'] !== $areaDiff['to_position'])
                                        <div class="grid grid-cols-[1.25rem_7rem_1fr] bg-red-50 px-4 text-red-900 dark:bg-red-950/35 dark:text-red-200"><span>-</span><span>Area order</span><span>{{ $areaDiff['from_position'] }}</span></div>
                                        <div class="grid grid-cols-[1.25rem_7rem_1fr] bg-green-50 px-4 text-green-900 dark:bg-green-950/35 dark:text-green-200"><span>+</span><span>Area order</span><span>{{ $areaDiff['to_position'] }}</span></div>
                                    @endif
                                @endif

                                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                    @forelse($areaDiff['lines'] as $lineIndex => $lineDiff)
                                        @php
                                            $lineLabel = $lineDiff['to_label'] ?? $lineDiff['from_label'] ?? 'Untitled line';
                                        @endphp
                                        <div wire:key="comparison-line-{{ $areaIndex }}-{{ $lineIndex }}" class="py-2">
                                            @if($lineDiff['status'] === 'added')
                                                <div class="grid grid-cols-[1.25rem_minmax(7rem,11rem)_1fr] bg-green-50 px-4 text-green-900 dark:bg-green-950/35 dark:text-green-200">
                                                    <span>+</span>
                                                    <span>{{ $lineLabel }}</span>
                                                    <span class="truncate">{{ $lineDiff['fields']['description']['to'] ?? 'Added line' }} · Qty {{ $lineDiff['fields']['qty']['to'] ?? '—' }}</span>
                                                </div>
                                            @elseif($lineDiff['status'] === 'removed')
                                                <div class="grid grid-cols-[1.25rem_minmax(7rem,11rem)_1fr] bg-red-50 px-4 text-red-900 dark:bg-red-950/35 dark:text-red-200">
                                                    <span>-</span>
                                                    <span>{{ $lineLabel }}</span>
                                                    <span class="truncate">{{ $lineDiff['fields']['description']['from'] ?? 'Removed line' }} · Qty {{ $lineDiff['fields']['qty']['from'] ?? '—' }}</span>
                                                </div>
                                            @elseif($lineDiff['status'] === 'changed')
                                                <div class="px-4 font-semibold text-amber-700 dark:text-amber-300">~ Line: {{ $lineLabel }}</div>
                                                @foreach($lineDiff['fields'] as $field)
                                                    @if($field['changed'])
                                                        <div class="grid grid-cols-[1.25rem_minmax(7rem,11rem)_1fr] bg-red-50 px-4 text-red-900 dark:bg-red-950/35 dark:text-red-200">
                                                            <span>-</span><span>{{ $field['label'] }}</span><span class="whitespace-pre-wrap break-words">{{ $field['from'] }}</span>
                                                        </div>
                                                        <div class="grid grid-cols-[1.25rem_minmax(7rem,11rem)_1fr] bg-green-50 px-4 text-green-900 dark:bg-green-950/35 dark:text-green-200">
                                                            <span>+</span><span>{{ $field['label'] }}</span><span class="whitespace-pre-wrap break-words">{{ $field['to'] }}</span>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            @else
                                                <div class="grid grid-cols-[1.25rem_minmax(7rem,11rem)_1fr] px-4 text-gray-500 dark:text-gray-400">
                                                    <span>&nbsp;</span>
                                                    <span>{{ $lineLabel }}</span>
                                                    <span class="truncate">{{ $lineDiff['fields']['description']['to'] ?? 'Unchanged' }} · Qty {{ $lineDiff['fields']['qty']['to'] ?? '—' }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="px-4 py-4 font-sans text-sm text-gray-500 dark:text-gray-400">No lines in this area.</div>
                                    @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-[#161b22]">
                    <x-filament::button wire:click="closeRevisionComparisonModal" color="gray">
                        Close
                    </x-filament::button>
                </div>
            </div>
        </div>
        @endif

        @if($approveRevisionModalOpen)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeApproveRevisionModal()"
            role="dialog"
            aria-modal="true"
            aria-label="Approve and lock this revision?"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
        >
            <div
                class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                wire:click="closeApproveRevisionModal"
            ></div>

            <div class="relative z-10 w-full max-w-md rounded-xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <x-heroicon-o-lock-closed class="h-5 w-5 text-green-600 dark:text-green-400" />
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Approve and lock this revision?</h2>
                </div>

                <div class="px-6 py-5 text-sm text-gray-600 dark:text-gray-300">
                    This revision will be locked against further editing.
                </div>

                <div class="flex items-center justify-end gap-3 rounded-b-xl border-t border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
                    <x-filament::button
                        wire:click="closeApproveRevisionModal"
                        color="gray"
                    >
                        Cancel
                    </x-filament::button>

                    <x-filament::button
                        wire:click="approveRevision"
                        wire:loading.attr="disabled"
                        wire:target="approveRevision"
                        color="success"
                    >
                        OK
                    </x-filament::button>
                </div>
            </div>
        </div>
        @endif

        @if($flagIssueModalOpen)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeFlagIssueModal()"
            role="dialog"
            aria-modal="true"
            aria-label="Flag issue"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
        >
            <div
                class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                wire:click="closeFlagIssueModal"
            ></div>

            <div class="relative z-10 w-full max-w-md rounded-xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <x-heroicon-o-flag class="h-5 w-5 text-amber-500" />
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Flag issue</h2>
                </div>

                <div class="space-y-3 px-6 py-5">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Reason</span>
                        <input
                            type="text"
                            wire:model="flagIssueNote"
                            maxlength="255"
                            autofocus
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        />
                    </label>
                </div>

                <div class="flex items-center justify-end gap-3 rounded-b-xl border-t border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
                    <x-filament::button
                        wire:click="closeFlagIssueModal"
                        color="gray"
                    >
                        Cancel
                    </x-filament::button>

                    <x-filament::button
                        wire:click="submitFlagIssue"
                        wire:loading.attr="disabled"
                        wire:target="submitFlagIssue"
                        color="warning"
                    >
                        OK
                    </x-filament::button>
                </div>
            </div>
        </div>
        @endif
    </div>
</x-filament-panels::page>
