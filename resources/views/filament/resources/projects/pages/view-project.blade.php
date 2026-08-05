<x-filament-panels::page>
@php
    $canViewPrices = $this->canViewPrices();
    $canEditLines = $this->canEditLines();
    $canEditPrices = $this->canEditPrices();
    $canEditCover = $this->canEditCover();
    $canCreateRevisions = $this->canCreateRevisions();
    $revisionLocked = $this->isViewingRevisionValidated;
    $projectLockedByAnother = $this->projectLockedByAnother();
    $projectEditLocked = $this->projectEditLocked();
    $projectLockOwnerName = $this->projectLockOwnerName();
    $projectHasCover = $this->projectHasCover();
    $showLineCovers = $canViewPrices && $projectHasCover && $this->showLineCovers;
    $lineGridColumns = match (true) {
        $canViewPrices && $projectHasCover => '20px 150px 65px 1fr 60px 76px 95px 95px 1fr 70px 48px',
        $canViewPrices => '20px 150px 65px 1fr 60px 76px 95px 1fr 70px 48px',
        default => '20px 150px 65px 1fr 60px 76px 1fr 48px',
    };
@endphp
<div
    x-data="{
        confirmDeleteLineId: null,
        confirmDeleteTenderId: null,
        confirmDeleteTenderName: '',
        lastProjectLockActivityAt: 0,
        releaseProjectLockUrl: @js(route('projects.lock.release', ['project' => $this->record])),
        csrfToken: @js(csrf_token()),
        touchProjectLockActivity() {
            const now = Date.now();

            if (now - this.lastProjectLockActivityAt < 30000) {
                return;
            }

            this.lastProjectLockActivityAt = now;
            $wire.touchProjectLockActivity();
        },
        releaseProjectLock() {
            const payload = new FormData();
            payload.append('_token', this.csrfToken);

            if (navigator.sendBeacon) {
                navigator.sendBeacon(this.releaseProjectLockUrl, payload);

                return;
            }

            fetch(this.releaseProjectLockUrl, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                keepalive: true,
            });
        },
        focusAdjacentLineField(field, direction, event) {
            if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }

            const column = field.dataset.lineColumn;

            if (! column) {
                return;
            }

            const fields = Array.from(document.querySelectorAll('[data-line-column]'))
                .filter((candidate) => candidate.dataset.lineColumn === column)
                .filter((candidate) => ! candidate.disabled && candidate.offsetParent !== null);
            const index = fields.indexOf(field);
            const target = fields[index + direction];

            if (! target) {
                return;
            }

            event.preventDefault();
            target.focus();
            target.select?.();
        },
    }"
    x-init="
        ['click', 'keydown', 'input', 'change', 'paste'].forEach((eventName) => {
            window.addEventListener(eventName, (event) => {
                if (! event.isTrusted) {
                    return;
                }

                touchProjectLockActivity();
            }, { passive: true });
        });
        window.addEventListener('pagehide', () => releaseProjectLock());
    "
    wire:poll.30s="heartbeat"
>

    @if($projectLockedByAnother)
    <div class="mb-4 flex items-center gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        <x-heroicon-o-lock-closed class="w-4 h-4 shrink-0" />
        <span>
            <strong>{{ $projectLockOwnerName ?? 'Another user' }}</strong> is editing this project. It is read-only until they leave or are inactive for {{ $this->projectLockInactivityMinutes() }} minutes.
        </span>
        <button
            wire:click="refreshProjectLock"
            title="Refresh lock status"
            class="ml-auto flex shrink-0 items-center gap-1 rounded-md bg-red-100 px-3 py-1 text-xs font-medium transition-colors hover:bg-red-200 dark:bg-red-800/40 dark:hover:bg-red-800/60"
        >
            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="refreshProjectLock" />
            Refresh
        </button>
    </div>
    @endif

    {{-- Concurrent editors banner --}}
    @if(! $projectLockedByAnother && $this->concurrentEditors->isNotEmpty())
    @php
        $editorNames = $this->concurrentEditors->map(fn($u) => $u->name ?? $u->email);
        $count = $editorNames->count();
        if ($count === 1) {
            $nameString = '<strong>'.$editorNames->first().'</strong>';
        } elseif ($count === 2) {
            $nameString = '<strong>'.$editorNames->first().'</strong> and <strong>'.$editorNames->last().'</strong>';
        } else {
            $listed = $editorNames->take(2)->map(fn($n) => '<strong>'.$n.'</strong>')->implode(', ');
            $remaining = $count - 2;
            $nameString = $listed.' and '.$remaining.' other'.($remaining > 1 ? 's' : '');
        }
    @endphp
    <div class="mb-4 flex items-center gap-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-4 py-3 text-sm text-blue-800 dark:text-blue-300">
        <x-heroicon-o-users class="w-4 h-4 shrink-0" />
        <span>{!! $nameString !!} {{ $count === 1 ? 'is' : 'are' }} also viewing this project right now. They cannot make changes while you have the edit lock.</span>
        <button
            wire:click="heartbeat"
            title="Refresh — updates may take up to a minute to appear"
            class="ml-auto shrink-0 rounded-md bg-blue-100 dark:bg-blue-800/40 px-3 py-1 text-xs font-medium hover:bg-blue-200 dark:hover:bg-blue-800/60 transition-colors flex items-center gap-1"
        >
            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="heartbeat" />
            Refresh
        </button>
    </div>
    @endif

    {{-- Viewing old revision banner --}}
    @php $activeRevisionId = $this->record->active_revision_id; @endphp
    @if($viewingRevisionId && $viewingRevisionId !== $activeRevisionId)
    <div class="mb-4 flex items-center gap-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
        <x-heroicon-o-exclamation-triangle class="w-4 h-4 shrink-0" />
        <span>You are viewing a historical revision. Changes made here will not affect the active revision.</span>
        <button
            wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : 'setActiveRevision('.$activeRevisionId.')' }}"
            class="ml-auto shrink-0 rounded-md bg-amber-100 dark:bg-amber-800/40 px-3 py-1 text-xs font-medium hover:bg-amber-200 dark:hover:bg-amber-800/60 transition-colors"
        >Switch to active</button>
    </div>
    @endif

    @php $revisionTotals = $this->getRevisionTotals(); @endphp
    <div @class([
        'mb-4 grid gap-3',
        'sm:grid-cols-4' => $canViewPrices && $projectHasCover,
        'sm:grid-cols-3' => $canViewPrices && ! $projectHasCover,
        'sm:grid-cols-2' => ! $canViewPrices,
    ])>
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total Qty</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($revisionTotals['qty']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Line Items</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($revisionTotals['items']) }}</div>
        </div>
        @if($canViewPrices)
            @if($projectHasCover)
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Net Project Total</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $this->record->formatCurrency($revisionTotals['net_value']) }}</div>
                </div>
            @endif
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Project Total</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $this->record->formatCurrency($revisionTotals['value']) }}</div>
            </div>
        @endif
    </div>

    {{-- Areas accordion list --}}
    <div class="space-y-3">
        @forelse($this->getAreas() as $area)
        <div
            wire:key="area-{{ $area->id }}"
            x-data="{ open: true }"
            class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden"
        >
            {{-- Area header --}}
            <div
                class="flex items-center gap-2 px-4 py-3 cursor-pointer select-none hover:bg-gray-50 dark:hover:bg-gray-800/60"
                @click="open = !open"
            >
                <x-heroicon-o-map-pin class="w-4 h-4 text-gray-400 shrink-0" />

                <span class="font-medium text-gray-900 dark:text-white">{{ $area->name }}</span>

                <span class="text-sm text-gray-400">({{ $area->lines->count() }} {{ Str::plural('item', $area->lines->count()) }})</span>

                <div class="flex-1"></div>

                @if($area->lines->isNotEmpty())
                    <span class="text-sm text-gray-500 dark:text-gray-400 mr-3">Qty: {{ $area->line_total_qty }}</span>
                    @if($canViewPrices)
                        @php
                            $areaTotal = $area->lines->sum(
                                fn ($line) => $line->totalLineTotalForProject($this->record)
                            );
                            $areaNetTotal = $projectHasCover
                                ? $area->lines->sum(fn ($line) => $line->netLineTotalForProject($this->record))
                                : null;
                        @endphp
                        @if($projectHasCover)
                        <span class="text-sm text-gray-500 dark:text-gray-400 mr-2">
                            Net:
                            <span class="font-medium text-gray-900 dark:text-white">{{ $this->record->formatCurrency($areaNetTotal) }}</span>
                        </span>
                        @endif
                        <span class="text-sm text-gray-500 dark:text-gray-400 mr-4">
                            Price:
                            <span class="font-medium text-gray-900 dark:text-white">{{ $this->record->formatCurrency($areaTotal) }}</span>
                        </span>
                    @endif
                @endif

                {{-- Area actions (stop accordion toggle) --}}
                <div class="flex items-center gap-1" @click.stop>

                    <button
                        wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : ($revisionLocked ? 'notifyApprovedRevisionLocked' : 'addProduct('.$area->id.')') }}"
                        @disabled(! $canEditLines || $projectEditLocked)
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Product 
                    </button>

                    <button
                        wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : ($revisionLocked ? 'notifyApprovedRevisionLocked' : 'openPasteProductsModal('.$area->id.')') }}"
                        @disabled(! $canEditLines || $projectEditLocked)
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Paste
                    </button>

                    <button
                        wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : ($revisionLocked ? 'notifyApprovedRevisionLocked' : 'addBlankLine('.$area->id.')') }}"
                        @disabled(! $canEditLines || $projectEditLocked)
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Blank 
                    </button>
                </div>

                <x-heroicon-o-chevron-down
                    class="w-4 h-4 text-gray-400 transition-transform ml-2"
                    ::class="open ? 'rotate-180' : ''"
                />
            </div>

            {{-- Lines --}}
            <div x-show="open" x-collapse>
                {{-- Column headers --}}
                <div
                    class="grid items-center gap-1.5 px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40"
                    style="grid-template-columns: {{ $lineGridColumns }}"
                >
                    <div></div>
                    <div>Code</div>
                    <div>Ref</div>
                    <div>Description</div>
                    <div class="text-center">Qty</div>
                    <div>Type</div>
                    @if($canViewPrices)
                        @if($projectHasCover)
                            @if($this->record->cover_direction === 'added')
                                <div class="text-center">Net</div>
                                <div class="text-right">Price</div>
                            @else
                                <div class="text-right">Net</div>
                                <div class="text-center">Price</div>
                            @endif
                        @else
                            <div class="text-center">Price</div>
                        @endif
                    @endif
                    <div class="flex items-center gap-2">
                        <span>{{ $showLineCovers ? 'Cover' : 'Notes' }}</span>
                        @if($canViewPrices && $projectHasCover)
                            <button
                                type="button"
                                wire:click="$toggle('showLineCovers')"
                                class="rounded border border-gray-300 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 hover:border-primary-500 hover:text-primary-600 dark:border-gray-600 dark:text-gray-400 dark:hover:text-primary-400"
                                title="{{ $showLineCovers ? 'Show notes' : 'Show cover fields' }}"
                            >
                                {{ $showLineCovers ? 'Notes' : 'Cover' }}
                            </button>
                        @endif
                    </div>
                    @if($canViewPrices)
                        <div>Status</div>
                    @endif
                    <div></div>
                </div>

                {{-- Sortable lines --}}
                <div
                    data-area-lines="{{ $area->id }}"
                    x-sort="(id, pos) => $wire.sortLine(parseInt(id), pos, {{ $area->id }})"
                    x-sort:config="{ group: 'projectLines', animation: 150, disabled: {{ (! $canEditLines || $revisionLocked || $projectEditLocked) ? 'true' : 'false' }} }"
                    class="divide-y divide-gray-100 dark:divide-gray-800 border-t border-gray-100 dark:border-gray-800"
                >
                    @foreach($area->lines as $line)
                    <div
                        wire:key="line-{{ $line->id }}"
                        x-sort:item="{{ $line->id }}"
                        class="grid items-center gap-1.5 px-4 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ match($line->type) {
                            \App\Enums\ProjectLineType::Modified => 'bg-amber-50/60 dark:bg-amber-900/10',
                            \App\Enums\ProjectLineType::Custom   => 'bg-blue-50/60 dark:bg-blue-900/10',
                            default => '',
                        } }}"
                        style="grid-template-columns: {{ $lineGridColumns }}"
                    >
                        {{-- Drag handle --}}
                        <div
                            x-sort:handle
                            class="cursor-grab text-gray-300 hover:text-gray-500 dark:hover:text-gray-400 flex items-center justify-center"
                        >
                            <x-heroicon-s-bars-2 class="w-4 h-4" />
                        </div>

                        {{-- Code --}}
                        <input
                            data-line-code-input
                            data-line-column="code"
                            wire:key="line-{{ $line->id }}-code-{{ $line->code }}"
                            value="{{ $line->code }}"
                            @disabled(! $canEditLines || $this->isViewingRevisionValidated || $projectEditLocked)
                            maxlength="255"
                            x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                            x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'code', $el.value)"
                            placeholder="–"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm font-mono hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white {{ match($line->type) {
                                \App\Enums\ProjectLineType::Modified => 'text-amber-600 dark:text-amber-400',
                                \App\Enums\ProjectLineType::Custom   => 'text-blue-600 dark:text-blue-400',
                                default => '',
                            } }}"
                        />

                        {{-- Ref --}}
                        <input
                            data-line-column="ref"
                            value="{{ $line->ref }}"
                            @disabled(! $canEditLines || $this->isViewingRevisionValidated || $projectEditLocked)
                            maxlength="6"
                            placeholder="–"
                            x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                            x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'ref', $el.value.toUpperCase().slice(0, 6))"
                            x-on:input="$el.value = $el.value.toUpperCase().slice(0, 6)"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm font-mono uppercase hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Description --}}
                        <input
                            data-line-column="description"
                            value="{{ $line->description }}"
                            @disabled(! $canEditLines || $this->isViewingRevisionValidated || $projectEditLocked)
                            x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                            x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'description', $el.value)"
                            maxlength="255"
                            placeholder="Description..."
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Qty --}}
                        <input
                            data-line-column="qty"
                            type="number"
                            value="{{ $line->qty }}"
                            @disabled(! $canEditLines || $this->isViewingRevisionValidated || $projectEditLocked)
                            min="{{ $line->isNoOffer() ? 0 : 1 }}"
                            max="4294967295"
                            x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                            x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'qty', Math.min(4294967295, Math.max({{ $line->isNoOffer() ? 0 : 1 }}, parseInt($el.value) || {{ $line->isNoOffer() ? 0 : 1 }})))"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Type badge --}}
                        <span class="inline-flex items-center justify-center rounded px-1.5 py-0.5 text-xs font-semibold {{ match($line->type) {
                            \App\Enums\ProjectLineType::Modified => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400',
                            \App\Enums\ProjectLineType::Custom   => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',
                            default                              => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                        } }}">
                            {{ $line->type->label() }}
                        </span>

                        @if($canViewPrices)
                            @if($projectHasCover)
                                @if($this->record->cover_direction === 'added')
                                    <input
                                        data-line-column="unit_price"
                                        type="number"
                                        step="0.01"
                                        max="99999999.99"
                                        value="{{ $line->unit_price }}"
                                        @disabled(! $canEditPrices || $this->isViewingRevisionValidated || $projectEditLocked)
                                        x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                                        x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                                        x-on:blur="
                                            const value = $el.value === '' ? '' : Math.min(99999999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                            $el.value = value;
                                            $wire.updateLineField({{ $line->id }}, 'unit_price', value);
                                        "
                                        placeholder="0.00"
                                        class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                                    />
                                    <div class="px-2 py-1 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $line->totalUnitPriceForProject($this->record) !== null ? number_format((float) $line->totalUnitPriceForProject($this->record), 2) : '—' }}
                                    </div>
                                @else
                                    <div class="px-2 py-1 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $line->netUnitPriceForProject($this->record) !== null ? number_format((float) $line->netUnitPriceForProject($this->record), 2) : '—' }}
                                    </div>
                                    <input
                                        data-line-column="unit_price"
                                        type="number"
                                        step="0.01"
                                        max="99999999.99"
                                        value="{{ $line->unit_price }}"
                                        @disabled(! $canEditPrices || $this->isViewingRevisionValidated || $projectEditLocked)
                                        x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                                        x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                                        x-on:blur="
                                            const value = $el.value === '' ? '' : Math.min(99999999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                            $el.value = value;
                                            $wire.updateLineField({{ $line->id }}, 'unit_price', value);
                                        "
                                        placeholder="0.00"
                                        class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                                    />
                                @endif
                            @else
                                <input
                                    data-line-column="unit_price"
                                    type="number"
                                    step="0.01"
                                    max="99999999.99"
                                    value="{{ $line->unit_price }}"
                                    @disabled(! $canEditPrices || $this->isViewingRevisionValidated || $projectEditLocked)
                                    x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                                    x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                                    x-on:blur="
                                        const value = $el.value === '' ? '' : Math.min(99999999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                        $el.value = value;
                                        $wire.updateLineField({{ $line->id }}, 'unit_price', value);
                                    "
                                    placeholder="0.00"
                                    class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                                />
                            @endif
                        @endif

                        @if($showLineCovers)
                            <div class="grid grid-cols-3 gap-1">
                                @foreach(['cover_1' => 'C1', 'cover_2' => 'C2', 'cover_3' => 'C3'] as $coverField => $coverLabel)
                                    <label class="relative block">
                                        <span class="sr-only">{{ $coverLabel }}</span>
                                        <input
                                            data-line-column="{{ $coverField }}"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="999.99"
                                            value="{{ $line->{$coverField} !== null ? number_format((float) $line->{$coverField}, 2, '.', '') : '' }}"
                                            @disabled(! $canEditCover || $revisionLocked || $projectEditLocked)
                                            x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                                            x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                                            x-on:blur="
                                                const value = $el.value === '' ? '' : Math.min(999.99, Math.max(0, Number.parseFloat($el.value) || 0)).toFixed(2);
                                                $el.value = value;
                                                $wire.updateLineField({{ $line->id }}, '{{ $coverField }}', value);
                                            "
                                            placeholder="{{ $coverLabel }}"
                                            class="w-full rounded border border-transparent bg-transparent px-1.5 py-1 pr-4 text-right text-xs hover:border-gray-300 focus:border-primary-500 focus:outline-none dark:hover:border-gray-600 text-gray-900 dark:text-white"
                                        />
                                        <span class="pointer-events-none absolute right-1 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">%</span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            {{-- Notes --}}
                            <input
                                data-line-column="notes"
                                value="{{ $line->notes }}"
                                @disabled(! $canEditLines || $revisionLocked || $projectEditLocked)
                                x-on:keydown.arrow-up="focusAdjacentLineField($el, -1, $event)"
                                x-on:keydown.arrow-down="focusAdjacentLineField($el, 1, $event)"
                                x-on:blur="$wire.updateLineField({{ $line->id }}, 'notes', $el.value)"
                                placeholder=""
                                class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-500 dark:text-gray-400"
                            />
                        @endif

                        @if($canViewPrices)
                            {{-- Status --}}
                            <div class="flex justify-center">
                                @php
                                    $displayStatus = $line->validation_flagged
                                        ? 'Flagged'
                                        : ($line->approved || $line->isNoOffer() ? 'Approved' : 'Pending');
                                @endphp
                                <span class="inline-flex items-center justify-center rounded px-2 py-0.5 text-xs font-semibold {{ match($displayStatus) {
                                    'Approved' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400',
                                    'Flagged' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400',
                                    'Pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400',
                                    default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                                } }}">
                                    {{ $displayStatus }}
                                </span>
                            </div>
                        @endif

                        {{-- Row actions --}}
                        <div class="flex items-center justify-end gap-0.5">
                            <button
                                wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : ($revisionLocked ? 'notifyApprovedRevisionLocked' : 'duplicateLine('.$line->id.')') }}"
                                @disabled(! $canEditLines || $projectEditLocked)
                                title="Duplicate row"
                                class="rounded p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                            >
                                <x-heroicon-o-document-duplicate class="w-4 h-4" />
                            </button>
                            <button
                                @click.stop="{{ $projectEditLocked ? '$wire.notifyProjectEditLocked()' : ($revisionLocked ? '$wire.notifyApprovedRevisionLocked()' : ($line->isBlankOrDefault() ? '$wire.deleteLine('.$line->id.')' : 'confirmDeleteLineId = '.$line->id)) }}"
                                @if($loop->last && $canEditLines && ! $revisionLocked && ! $projectEditLocked)
                                    x-on:keydown.tab="
                                        if (! $event.shiftKey) {
                                            $event.preventDefault();
                                            $wire.addBlankLine({{ $area->id }}).then(() => {
                                                requestAnimationFrame(() => {
                                                    requestAnimationFrame(() => {
                                                        const inputs = document.querySelectorAll('[data-area-lines=&quot;{{ $area->id }}&quot;] [data-line-code-input]');
                                                        inputs[inputs.length - 1]?.focus();
                                                    });
                                                });
                                            });
                                        }
                                    "
                                @endif
                                @disabled(! $canEditLines || $projectEditLocked)
                                title="Delete row"
                                class="rounded p-1 text-gray-400 hover:text-red-500 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700"
                            >
                                <x-heroicon-o-trash class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                    @endforeach

                    @if($area->lines->isEmpty())
                    <div class="px-8 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                        @if($canEditLines && ! $revisionLocked && ! $projectEditLocked)
                            No items in this area. Add a
                            <button
                                wire:click="addBlankLine({{ $area->id }})"
                                class="text-primary-500 hover:underline">
                                blank row
                            </button> or a <button
                                wire:click="addProduct({{ $area->id }})"
                                class="text-primary-500 hover:underline">
                                product
                            </button>
                        @else
                            No items in this area.
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-700 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
            No areas defined. Click <strong>Areas</strong> above to add rooms or floors.
        </div>
        @endforelse
    </div>

    {{-- Product Picker Modal --}}
    @if($productPickerOpen)
    <div
        x-data
        x-on:keydown.escape.window="$wire.closeProductPicker()"
        role="dialog"
        aria-modal="true"
        aria-label="Add Products"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
    >
        {{-- Backdrop --}}
        <div
            class="absolute inset-0 bg-black/60 backdrop-blur-sm"
            wire:click="closeProductPicker"
        ></div>

        {{-- Modal panel --}}
        <div class="relative z-10 w-full max-w-4xl flex flex-col bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700" style="max-height: 85vh">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Add Products to Area</h2>
                <button
                    wire:click="closeProductPicker"
                    class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            {{-- Search & Filter bar --}}
            <div class="flex gap-3 px-6 py-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <div class="relative flex-1">
                    <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                    <input
                        wire:model.live.debounce.250ms="productSearch"
                        type="search"
                        placeholder="Search by name, SKU or description..."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 pl-9 pr-3 py-2 text-sm text-gray-900 dark:text-white placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    />
                </div>
                <select
                    wire:model.live="productSiteFilter"
                    class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                >
                    <option value="">All sites</option>
                    @foreach($this->productSiteOptions as $site)
                    <option value="{{ $site }}">{{ $site }}</option>
                    @endforeach
                </select>
                <select
                    wire:model.live="productTypeFilter"
                    class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                >
                    <option value="">All types</option>
                    @foreach($this->productTypeOptions as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Column headers --}}
            <div class="grid gap-3 px-6 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/40 border-b border-gray-100 dark:border-gray-800 shrink-0"
                style="grid-template-columns: 32px 1fr 120px 80px 80px 70px">
                <div></div>
                <div>Product</div>
                <div>SKU</div>
                <div>Type</div>
                <div class="text-center">Site</div>
                <div class="text-center">Qty</div>
            </div>

            {{-- Product list --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($this->productPickerProducts as $product)
                @php $isSelected = isset($productSelections[$product->id]); @endphp
                <div
                    wire:key="picker-product-{{ $product->id }}"
                    x-on:click="$wire.toggleProductSelection({{ $product->id }})"
                    class="grid items-center gap-3 px-6 py-3 cursor-pointer select-none transition-colors
                        {{ $isSelected
                            ? 'bg-primary-50 dark:bg-primary-900/20 hover:bg-primary-100 dark:hover:bg-primary-900/30'
                            : 'hover:bg-gray-50 dark:hover:bg-gray-800/50' }}"
                    style="grid-template-columns: 32px 1fr 120px 80px 80px 70px"
                >
                    {{-- Checkbox --}}
                    <div class="flex items-center justify-center pointer-events-none">
                        <div class="w-4 h-4 rounded border-2 flex items-center justify-center transition-colors
                            {{ $isSelected
                                ? 'bg-primary-600 border-primary-600'
                                : 'border-gray-400 dark:border-gray-500 bg-white dark:bg-gray-800' }}">
                            @if($isSelected)
                            <x-heroicon-s-check class="w-2.5 h-2.5 text-white" />
                            @endif
                        </div>
                    </div>

                    {{-- Name + description --}}
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $product->displayDescription() }}</div>
                    </div>

                    {{-- SKU --}}
                    <div class="text-xs font-mono text-gray-600 dark:text-gray-400 truncate">{{ $product->sku }}</div>

                    {{-- Type --}}
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $product->type_name ?? '—' }}</div>

                    {{-- Site badge --}}
                    <div class="flex justify-center">
                        @if($product->site)
                        <span class="lux-compact-badge {{ \App\Filament\Support\BadgeStyle::classes($product->site) }}">
                            {{ $product->site }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </div>

                    {{-- Qty input (only when selected) --}}
                    <div class="flex justify-center" x-on:click.stop>
                        @if($isSelected)
                        <input
                            type="number"
                            min="1"
                            value="{{ $productSelections[$product->id]['qty'] }}"
                            wire:change="setProductSelectionQty({{ $product->id }}, $event.target.value)"
                            class="w-16 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1 text-sm text-center text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                        />
                        @else
                        <span class="text-gray-300 dark:text-gray-600 text-sm">—</span>
                        @endif
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-16 text-sm text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-magnifying-glass class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600" />
                    No products found{{ $productSearch || $productSiteFilter || $productTypeFilter ? ' for your search' : '' }}.
                </div>
                @endforelse
            </div>

            {{-- Pagination --}}
            @if($this->productPickerProducts->lastPage() > 1)
            <div class="flex items-center justify-between px-6 py-2.5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 shrink-0 text-sm text-gray-500 dark:text-gray-400">
                <span>
                    Page {{ $this->productPickerProducts->currentPage() }} of {{ $this->productPickerProducts->lastPage() }}
                    &middot; {{ $this->productPickerProducts->total() }} products
                </span>
                <div class="flex items-center gap-1">
                    <button
                        wire:click="$set('productPage', {{ max(1, $productPage - 1) }})"
                        @disabled($productPage <= 1)
                        class="rounded-lg px-2.5 py-1 font-medium transition-colors
                            {{ $productPage <= 1
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}"
                    >← Prev</button>
                    <button
                        wire:click="$set('productPage', {{ min($this->productPickerProducts->lastPage(), $productPage + 1) }})"
                        @disabled(! $this->productPickerProducts->hasMorePages())
                        class="rounded-lg px-2.5 py-1 font-medium transition-colors
                            {{ ! $this->productPickerProducts->hasMorePages()
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}"
                    >Next →</button>
                </div>
            </div>
            @endif

            {{-- Footer --}}
            <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-b-xl shrink-0">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    @if(count($productSelections) > 0)
                    <span class="font-medium text-primary-600 dark:text-primary-400">{{ count($productSelections) }} {{ Str::plural('product', count($productSelections)) }} selected</span>
                    @else
                    Click a row to select products
                    @endif
                </span>
                <div class="flex items-center gap-3">
                    <button
                        wire:click="closeProductPicker"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="addSelectedProducts"
                        @disabled(count($productSelections) === 0)
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                            {{ count($productSelections) > 0
                                ? 'fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 cursor-not-allowed' }}"
                    >
                        Add {{ count($productSelections) > 0 ? count($productSelections).' '.Str::plural('Product', count($productSelections)) : 'Products' }}
                    </button>
                </div>
            </div>

        </div>
    </div>
    @endif

    {{-- Paste Products Modal --}}
    @if($pasteProductsModalOpen)
    <div
        x-data
        x-on:keydown.escape.window="$wire.closePasteProductsModal()"
        role="dialog"
        aria-modal="true"
        aria-label="Paste Products"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
    >
        <div
            class="absolute inset-0 bg-black/60 backdrop-blur-sm"
            wire:click="closePasteProductsModal"
        ></div>

        <div
            x-data="{
                pastedProductData: @js($pastedProductData),
                visibleTab: '→',
                displayTabs(text) {
                    return text
                        .replace(/\r\n?/g, '\n')
                        .replaceAll('\t', this.visibleTab)
                        .split('\n')
                        .map((line) => line.replace(new RegExp(this.visibleTab + '+\\s*$'), ''))
                        .join('\n')
                },
                insertText(event, text) {
                    const textarea = event.target
                    const start = textarea.selectionStart
                    const end = textarea.selectionEnd

                    this.pastedProductData = this.pastedProductData.slice(0, start) + text + this.pastedProductData.slice(end)

                    this.$nextTick(() => {
                        textarea.selectionStart = start + text.length
                        textarea.selectionEnd = start + text.length
                        textarea.dispatchEvent(new Event('input', { bubbles: true }))
                    })
                },
                insertTab(event) {
                    this.insertText(event, this.visibleTab)
                },
                normalisePastedTabs(event) {
                    const pastedText = event.clipboardData?.getData('text') ?? ''

                    if (! pastedText.includes('\t')) {
                        return
                    }

                    event.preventDefault()
                    this.insertText(event, this.displayTabs(pastedText))
                }
            }"
            class="relative z-10 w-full max-w-xl bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700"
        >
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Paste Products</h2>
                <button
                    wire:click="closePasteProductsModal"
                    class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="px-6 py-5">
                <div class="mb-4 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors
                        {{ $pasteProductsMode === 'misos' ? 'border-primary-500 bg-primary-500/10 text-gray-900 dark:text-white' : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600' }}"
                    >
                        <input
                            type="radio"
                            wire:model.live="pasteProductsMode"
                            value="misos"
                            class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                        />
                        <span>Paste from Misos</span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors
                        {{ $pasteProductsMode === 'technical' ? 'border-primary-500 bg-primary-500/10 text-gray-900 dark:text-white' : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600' }}"
                    >
                        <input
                            type="radio"
                            wire:model.live="pasteProductsMode"
                            value="technical"
                            class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                        />
                        <span>Paste Technical</span>
                    </label>
                </div>

                <label for="pasted-product-data" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Paste product data<br>
                    @if($pasteProductsMode === 'technical')
                        Format:
                        <span class="text-green-600 dark:text-green-400">area name</span><br>
                        <span class="text-green-600 dark:text-green-400">sku</span> [tab]
                        <span class="text-green-600 dark:text-green-400">ref</span> [tab]
                        <span class="text-green-600 dark:text-green-400">qty</span> [tab]
                        <span class="text-green-600 dark:text-green-400">description</span><br>
                        Separate areas with one blank row.
                    @else
                        Format:
                        <span class="text-green-600 dark:text-green-400">qty</span> [tab]
                        <span class="text-green-600 dark:text-green-400">sku</span> [tab]
                        <span class="text-green-600 dark:text-green-400">description</span>
                        @if($canEditPrices)
                            [tab]
                            <span class="text-green-600 dark:text-green-400">price</span> [tab]
                            <span class="text-green-600 dark:text-green-400">discount</span> [tab]
                            <span class="text-green-600 dark:text-green-400">nett each</span> [tab]
                            <span class="text-green-600 dark:text-green-400">line total</span>
                        @endif
                    @endif
                </label>

                @if($pasteProductsMode === 'technical')
                    <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                        Technical paste replaces all existing areas and products in this revision. This cannot be undone.
                    </div>
                @endif

                <textarea
                    id="pasted-product-data"
                    x-model="pastedProductData"
                    wire:model="pastedProductData"
                    x-on:keydown.tab="if (! $event.shiftKey) { $event.preventDefault(); insertTab($event) }"
                    x-on:paste="normalisePastedTabs($event)"
                    rows="10"
                    class="mt-2 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm font-mono text-gray-900 dark:text-white placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                ></textarea>

                @if($pasteProductsMode === 'misos')
                    <label class="mt-4 flex items-center justify-between gap-4 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-gray-700">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Match existing lines across all areas</span>
                        <input
                            type="checkbox"
                            wire:model.live="pasteAcrossAllAreas"
                            class="h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                        />
                    </label>
                @endif

                @if($pasteProductsError)
                <div class="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $pasteProductsError }}</span>
                </div>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-b-xl">
                <button
                    wire:click="closePasteProductsModal"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                    Cancel
                </button>
                <button
                    wire:click="addPastedProducts"
                    x-bind:disabled="pastedProductData.trim() === ''"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                        bg-primary-600 text-white hover:bg-primary-700
                        disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400
                        dark:disabled:bg-gray-700 dark:disabled:text-gray-500"
                >
                    Add Products
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Tenders Modal --}}
    @if($tendersModalOpen)
    <div
        x-data
        x-on:keydown.escape.window="$wire.closeTendersModal()"
        role="dialog"
        aria-modal="true"
        aria-label="Tenders"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
    >
        <div
            class="absolute inset-0 bg-black/60 backdrop-blur-sm"
            wire:click="closeTendersModal"
        ></div>

        <div class="relative z-10 w-full max-w-xl flex flex-col bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700" style="max-height: 80vh">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Tenders</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Contractors tendering for this project.</p>
                </div>
                <button
                    wire:click="closeTendersModal"
                    class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($this->projectTenders as $tender)
                @php
                    $tenderCity = $tender->billing_city ?? ($tender->account_payload['BillingCity'] ?? null);
                @endphp
                <div class="flex items-center gap-4 px-6 py-4">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $tender->is_primary ? 'bg-primary-500/15 text-primary-600 ring-1 ring-primary-500/30 dark:text-primary-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                        <x-heroicon-o-building-office-2 class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <div class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $tender->account_name }}</div>
                            @if($tender->is_primary)
                            <span class="shrink-0 rounded-md bg-primary-500/15 px-2 py-0.5 text-xs font-semibold text-primary-700 ring-1 ring-primary-500/30 dark:text-primary-300">Primary</span>
                            @endif
                        </div>
                        <div class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $tenderCity ?: 'No city recorded' }}</div>
                    </div>
                    @if($this->canManageProjectTenders())
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button
                            wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : 'makeTenderPrimary('.$tender->id.')' }}"
                            @disabled($tender->is_primary || $projectEditLocked)
                            class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors
                                {{ $tender->is_primary || $projectEditLocked
                                    ? 'cursor-not-allowed text-gray-300 dark:text-gray-600'
                                    : 'text-gray-600 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-400 dark:hover:bg-primary-950/30 dark:hover:text-primary-300' }}"
                            title="{{ $tender->is_primary ? 'Primary tender' : 'Make primary' }}"
                        >
                            Make Primary
                        </button>
                        <button
                            @if($projectEditLocked)
                                @click="$wire.notifyProjectEditLocked()"
                            @else
                                @click="confirmDeleteTenderId = {{ $tender->id }}; confirmDeleteTenderName = @js($tender->account_name)"
                            @endif
                            @disabled($projectEditLocked)
                            class="rounded-lg p-1.5 text-gray-400 transition-colors {{ $projectEditLocked ? 'cursor-not-allowed opacity-50' : 'hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30 dark:hover:text-red-400' }}"
                            title="Remove tender"
                        >
                            <x-heroicon-o-trash class="h-5 w-5" />
                        </button>
                    </div>
                    @endif
                </div>
                @empty
                <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                        <x-heroicon-o-briefcase class="h-6 w-6" />
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">No tenders added yet</p>
                    <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">Add Salesforce Accounts for contractors tendering on this project.</p>
                </div>
                @endforelse
            </div>

            <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-b-xl shrink-0">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->projectTenders->count() }} {{ Str::plural('tender', $this->projectTenders->count()) }}
                </span>
                <div class="flex items-center gap-3">
                    <button
                        wire:click="closeTendersModal"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        Close
                    </button>
                    @if($this->canManageProjectTenders())
                    <button
                        wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : 'openTenderAccountPicker' }}"
                        @disabled($projectEditLocked)
                        class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action rounded-lg px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Add tender
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Delete Tender Confirmation Modal --}}
    <div
        x-show="confirmDeleteTenderId !== null"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:keydown.escape.window="confirmDeleteTenderId = null"
        class="fixed inset-0 z-[10001] flex items-center justify-center p-4"
        style="display: none"
    >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="confirmDeleteTenderId = null"></div>
        <div class="relative z-10 w-full max-w-sm bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700 p-6">
            <div class="flex items-start gap-4 mb-5">
                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30">
                    <x-heroicon-o-trash class="w-5 h-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Remove this tender?</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-200" x-text="confirmDeleteTenderName"></span>
                        will be removed from this project.
                    </p>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button
                    @click="confirmDeleteTenderId = null"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                    Cancel
                </button>
                <button
                    @click="$wire.removeTender(confirmDeleteTenderId); confirmDeleteTenderId = null"
                    @disabled($projectEditLocked)
                    class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors"
                >
                    Remove
                </button>
            </div>
        </div>
    </div>

    {{-- Tender Account Picker Modal --}}
    @if($tenderAccountPickerOpen)
    <div
        x-data
        x-on:keydown.escape.window="$wire.closeTenderAccountPicker()"
        role="dialog"
        aria-modal="true"
        aria-label="Add Tender"
        class="fixed inset-0 z-[10000] flex items-center justify-center p-4"
    >
        <div
            class="absolute inset-0 bg-black/60 backdrop-blur-sm"
            wire:click="closeTenderAccountPicker"
        ></div>

        <div class="relative z-10 w-full max-w-3xl flex flex-col bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700" style="max-height: 85vh">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Add Tender</h2>
                <button
                    wire:click="closeTenderAccountPicker"
                    class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="px-6 py-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                    <input
                        wire:model.live.debounce.300ms="tenderAccountSearch"
                        type="search"
                        placeholder="Search Salesforce Accounts..."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 pl-9 pr-3 py-2 text-sm text-gray-900 dark:text-white placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    />
                </div>
            </div>

            <div class="grid gap-3 px-6 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/40 border-b border-gray-100 dark:border-gray-800 shrink-0"
                style="grid-template-columns: 32px 1fr 160px 160px">
                <div></div>
                <div>Account</div>
                <div>City</div>
                <div>CEF Region</div>
            </div>

            @php
                $visibleTenderAccounts = $this->visibleTenderAccountSearchResults();
            @endphp

            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @if($tenderAccountSearchError)
                <div class="mx-6 my-4 rounded-lg border border-amber-500/40 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    <div class="flex gap-2">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <div class="font-semibold">Salesforce Account search unavailable</div>
                            <div class="mt-1 text-amber-700 dark:text-amber-300">{{ $tenderAccountSearchError }}</div>
                        </div>
                    </div>
                </div>
                @else
                @forelse($visibleTenderAccounts as $account)
                @php
                    $isSelected = isset($tenderAccountSelections[$account['id']]);
                    $alreadyAdded = $this->projectTenders->contains('salesforce_account_id', $account['id']);
                @endphp
                <div
                    wire:key="tender-account-{{ $account['id'] }}"
                    @unless($alreadyAdded)
                    x-on:click="$wire.toggleTenderAccountSelection(@js($account['id']))"
                    @endunless
                    class="grid items-center gap-3 px-6 py-3 select-none transition-colors
                        {{ $alreadyAdded
                            ? 'cursor-not-allowed opacity-60'
                            : ($isSelected
                                ? 'cursor-pointer bg-primary-50 dark:bg-primary-900/20 hover:bg-primary-100 dark:hover:bg-primary-900/30'
                                : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50') }}"
                    style="grid-template-columns: 32px 1fr 160px 160px"
                >
                    <div class="flex items-center justify-center pointer-events-none">
                        <div class="w-4 h-4 rounded border-2 flex items-center justify-center transition-colors
                            {{ $isSelected
                                ? 'bg-primary-600 border-primary-600'
                                : 'border-gray-400 dark:border-gray-500 bg-white dark:bg-gray-800' }}">
                            @if($isSelected)
                            <x-heroicon-s-check class="w-2.5 h-2.5 text-white" />
                            @endif
                        </div>
                    </div>
                    <div class="min-w-0 text-sm font-medium text-gray-900 dark:text-white truncate">{{ $account['name'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 truncate">{{ $account['billing_city'] ?? '—' }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 truncate">{{ $account['cef_region'] ?? '—' }}</div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-16 text-sm text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-magnifying-glass class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600" />
                    No Salesforce Accounts found{{ $tenderAccountSearch ? ' for your search' : '' }}.
                </div>
                @endforelse
                @endif
            </div>

            <div class="flex items-center justify-between px-6 py-2.5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 shrink-0 text-sm text-gray-500 dark:text-gray-400">
                <span>Page {{ $tenderAccountPage }}</span>
                <div class="flex items-center gap-1">
                    <button
                        wire:click="previousTenderAccountPage"
                        @disabled($tenderAccountPage <= 1)
                        class="rounded-lg px-2.5 py-1 font-medium transition-colors
                            {{ $tenderAccountPage <= 1
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}"
                    >← Prev</button>
                    <button
                        wire:click="nextTenderAccountPage"
                        @disabled(! $this->tenderAccountSearchHasMoreResults())
                        class="rounded-lg px-2.5 py-1 font-medium transition-colors
                            {{ ! $this->tenderAccountSearchHasMoreResults()
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}"
                    >Next →</button>
                </div>
            </div>

            <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-b-xl shrink-0">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    @if(count($tenderAccountSelections) > 0)
                    <span class="font-medium text-primary-600 dark:text-primary-400">{{ count($tenderAccountSelections) }} {{ Str::plural('account', count($tenderAccountSelections)) }} selected</span>
                    @else
                    Click a row to select Accounts
                    @endif
                </span>
                <div class="flex items-center gap-3">
                    <button
                        wire:click="closeTenderAccountPicker"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="addSelectedTenders"
                        @disabled(count($tenderAccountSelections) === 0)
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                            {{ count($tenderAccountSelections) > 0
                                ? 'fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 cursor-not-allowed' }}"
                    >
                        Add {{ count($tenderAccountSelections) > 0 ? count($tenderAccountSelections).' '.Str::plural('Tender', count($tenderAccountSelections)) : 'Tenders' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Revisions Modal --}}
    @if($revisionsModalOpen)
    <div
        x-data
        x-on:keydown.escape.window="$wire.set('revisionsModalOpen', false)"
        role="dialog"
        aria-modal="true"
        aria-label="Manage Revisions"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
    >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" wire:click="$set('revisionsModalOpen', false)"></div>

        <div class="relative z-10 w-full max-w-xl bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700 flex flex-col" style="max-height: 80vh">
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Revisions</h2>
                <button
                    wire:click="$set('revisionsModalOpen', false)"
                    class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            {{-- Revision list --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($this->projectRevisions as $revision)
                @php
                    $isActive   = $revision->id === $this->record->active_revision_id;
                    $isViewing  = $revision->id === $viewingRevisionId;
                @endphp
                <div class="flex items-center gap-4 px-6 py-4 {{ $isViewing ? 'bg-primary-50 dark:bg-primary-900/10' : '' }}">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $revision->label() }}</span>
                            @if($isActive)
                            <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900/30 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">Active</span>
                            @endif
                            @if($revision->validated)
                            <span class="inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-400">Validated</span>
                            @endif
                            @if($isViewing && !$isActive)
                            <span class="inline-flex items-center rounded-full bg-primary-100 dark:bg-primary-900/30 px-2 py-0.5 text-xs font-medium text-primary-700 dark:text-primary-400">Viewing</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Created by {{ $revision->creator?->name ?? $revision->creator?->email ?? 'Unknown' }}
                            &middot; {{ $revision->created_at->format('M d Y H:i') }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if(!$isViewing && $canCreateRevisions)
                        <button
                            wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : 'setActiveRevision('.$revision->id.')' }}"
                            @disabled($projectEditLocked)
                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
                        >
                            View{{ !$isActive ? ' and Set Active' : '' }}
                        </button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Footer --}}
            @if($canCreateRevisions)
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
                    <button
                        wire:click="{{ $projectEditLocked ? 'notifyProjectEditLocked' : 'createNewRevision' }}"
                        wire:loading.attr="disabled"
                        @disabled($projectEditLocked)
                        class="w-full flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
                    >
                        <x-heroicon-o-plus class="w-4 h-4" />
                        <span wire:loading.remove wire:target="createNewRevision">Create New Revision</span>
                        <span wire:loading wire:target="createNewRevision">Creating...</span>
                    </button>
                    <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">
                        Copies all areas and lines from the current revision into a new one.
                    </p>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Delete Line Confirmation Modal --}}
    <div
        x-show="confirmDeleteLineId !== null"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:keydown.escape.window="confirmDeleteLineId = null"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
        style="display: none"
    >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="confirmDeleteLineId = null"></div>
        <div class="relative z-10 w-full max-w-sm bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700 p-6">
            <div class="flex items-start gap-4 mb-5">
                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30">
                    <x-heroicon-o-trash class="w-5 h-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete this line?</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">This action cannot be undone.</p>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button
                    @click="confirmDeleteLineId = null"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                    Cancel
                </button>
                <button
                    @click="$wire.deleteLine(confirmDeleteLineId); confirmDeleteLineId = null"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>

    @if($this->projectLockAutoReleased)
    <div
        role="dialog"
        aria-modal="true"
        aria-label="Project lock released"
        class="fixed inset-0 z-[10002] flex items-center justify-center p-4"
    >
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
        <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                    <x-heroicon-o-clock class="h-5 w-5" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Project lock released</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Your edit lock was released after {{ $this->projectLockInactivityMinutes() }} minutes of inactivity. This project is now read-only in this tab.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <a
                    href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('index') }}"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action rounded-lg px-4 py-2 text-sm font-medium"
                >
                    Back to projects
                </a>
            </div>
        </div>
    </div>
    @endif

</div>
</x-filament-panels::page>
