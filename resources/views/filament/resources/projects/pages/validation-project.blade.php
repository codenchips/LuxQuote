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
                                        {{ $issue['rrp'] !== null ? '£'.number_format((float) $issue['rrp'], 2) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Quote</span>
                                    @if(! $issue['approved'] && $canEditPrices && $canUpdateValidationLines)
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value="{{ $issue['quote_price'] }}"
                                            x-on:blur="$wire.updateIssueQuotePrice({{ \Illuminate\Support\Js::from($issue['key']) }}, $el.value)"
                                            class="h-[34px] w-full rounded-lg border border-gray-300 bg-white px-2 py-0 text-left text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                    @else
                                        <span class="block h-[34px] px-1 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">
                                            {{ ($issue['quote_price'] ?? null) !== null ? '£'.number_format((float) $issue['quote_price'], 2) : '—' }}
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
                                        {{ ($issue['rrp'] ?? null) !== null ? '£'.number_format((float) $issue['rrp'], 2) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unit</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm text-gray-500 dark:text-gray-400">
                                        {{ ($issue['unit_price'] ?? null) !== null ? '£'.number_format((float) $issue['unit_price'], 2) : '—' }}
                                    </span>
                                </label>

                                <label class="w-24 space-y-1">
                                    <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Net</span>
                                    <span class="block h-[34px] px-1 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        {{ ($issue['net_price'] ?? null) !== null ? '£'.number_format((float) $issue['net_price'], 2) : '—' }}
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
                                                        const value = $el.value === '' ? '' : Number.parseFloat($el.value).toFixed(2);
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
                            @if($issue['type'] === 'price_mismatch' && $canViewPrices && $canEditPrices && $canUpdateValidationLines && ($issue['rrp'] ?? null) !== null)
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
                        {{ $line['unit_price'] !== null ? '£'.number_format((float) $line['unit_price'], 2) : '—' }}
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
