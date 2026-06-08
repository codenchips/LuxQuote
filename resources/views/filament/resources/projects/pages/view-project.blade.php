<x-filament-panels::page>
<div x-data="{ confirmDeleteLineId: null }" wire:poll.30s="heartbeat">

    {{-- Concurrent editors banner --}}
    @if($this->concurrentEditors->isNotEmpty())
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
        <span>{!! $nameString !!} {{ $count === 1 ? 'is' : 'are' }} also viewing this project right now.</span>
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
            wire:click="setActiveRevision({{ $activeRevisionId }})"
            class="ml-auto shrink-0 rounded-md bg-amber-100 dark:bg-amber-800/40 px-3 py-1 text-xs font-medium hover:bg-amber-200 dark:hover:bg-amber-800/60 transition-colors"
        >Switch to active</button>
    </div>
    @endif

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
                <span class="text-sm font-medium text-gray-900 dark:text-white mr-4">
                    £{{ number_format($area->line_total, 2) }}
                </span>
                @endif

                {{-- Area actions (stop accordion toggle) --}}
                <div class="flex items-center gap-1" @click.stop>

                    <button
                        wire:click="addProduct({{ $area->id }})"
                        @disabled($this->isViewingRevisionValidated)
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Product 
                    </button>

                    <button
                        wire:click="openPasteProductsModal({{ $area->id }})"
                        @disabled($this->isViewingRevisionValidated)
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Paste
                    </button>

                    <button
                        wire:click="addBlankLine({{ $area->id }})"
                        @disabled($this->isViewingRevisionValidated)
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
                    class="grid items-center gap-2 px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40"
                    style="grid-template-columns: 20px 110px 65px 1fr 60px 90px 95px 1fr 70px 60px"
                >
                    <div></div>
                    <div>Code</div>
                    <div>Ref</div>
                    <div>Description</div>
                    <div class="text-center">Qty</div>
                    <div>Type</div>
                    <div class="text-center">Unit Price</div>
                    <div>Notes</div>
                    <div>Status</div>
                    <div></div>
                </div>

                {{-- Sortable lines --}}
                <div
                    x-sort="(id, pos) => $wire.sortLine(parseInt(id), pos, {{ $area->id }})"
                    x-sort:config="{ group: 'projectLines', animation: 150, disabled: {{ $this->isViewingRevisionValidated ? 'true' : 'false' }} }"
                    class="divide-y divide-gray-100 dark:divide-gray-800 border-t border-gray-100 dark:border-gray-800"
                >
                    @foreach($area->lines as $line)
                    <div
                        wire:key="line-{{ $line->id }}"
                        x-sort:item="{{ $line->id }}"
                        class="grid items-center gap-2 px-4 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ match($line->type) {
                            \App\Enums\ProjectLineType::Modified => 'bg-amber-50/60 dark:bg-amber-900/10',
                            \App\Enums\ProjectLineType::Custom   => 'bg-blue-50/60 dark:bg-blue-900/10',
                            default => '',
                        } }}"
                        style="grid-template-columns: 20px 110px 65px 1fr 60px 90px 95px 1fr 70px 60px"
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
                            value="{{ $line->code }}"
                            @disabled($this->isViewingRevisionValidated)
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
                            value="{{ $line->ref }}"
                            @disabled($this->isViewingRevisionValidated)
                            maxlength="6"
                            placeholder="–"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'ref', $el.value.toUpperCase().slice(0, 6))"
                            x-on:input="$el.value = $el.value.toUpperCase().slice(0, 6)"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm font-mono uppercase hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Description --}}
                        <input
                            value="{{ $line->description }}"
                            @disabled($this->isViewingRevisionValidated)
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'description', $el.value)"
                            placeholder="Description..."
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Qty --}}
                        <input
                            type="number"
                            value="{{ $line->qty }}"
                            @disabled($this->isViewingRevisionValidated)
                            min="1"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'qty', parseInt($el.value) || 1)"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Type badge --}}
                        <span class="inline-flex items-center justify-center rounded px-2 py-0.5 text-xs font-semibold {{ match($line->type) {
                            \App\Enums\ProjectLineType::Modified => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400',
                            \App\Enums\ProjectLineType::Custom   => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',
                            default                              => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                        } }}">
                            {{ $line->type->label() }}
                        </span>

                        {{-- Unit Price --}}
                        <input
                            type="number"
                            step="0.01"
                            value="{{ $line->unit_price }}"
                            @disabled($this->isViewingRevisionValidated)
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'unit_price', $el.value)"
                            placeholder="0.00"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Notes --}}
                        <input
                            value="{{ $line->notes }}"
                            @disabled($this->isViewingRevisionValidated)
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'notes', $el.value)"
                            placeholder=""
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-500 dark:text-gray-400"
                        />

                        {{-- Status --}}
                        <span class="text-sm text-gray-400 text-center">–</span>

                        {{-- Row actions --}}
                        <div class="flex items-center justify-end gap-0.5">
                            <button
                                wire:click="duplicateLine({{ $line->id }})"
                                @disabled($this->isViewingRevisionValidated)
                                title="Duplicate row"
                                class="rounded p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                            >
                                <x-heroicon-o-document-duplicate class="w-4 h-4" />
                            </button>
                            <button
                                @click.stop="confirmDeleteLineId = {{ $line->id }}"
                                @disabled($this->isViewingRevisionValidated)
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
                        <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $product->product_name }}</div>
                        @if($product->description)
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $product->description }}</div>
                        @endif
                    </div>

                    {{-- SKU --}}
                    <div class="text-xs font-mono text-gray-600 dark:text-gray-400 truncate">{{ $product->sku }}</div>

                    {{-- Type --}}
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $product->type_name ?? '—' }}</div>

                    {{-- Site badge --}}
                    <div class="flex justify-center">
                        @if($product->site)
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
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
                                ? 'bg-primary-600 hover:bg-primary-700 text-white'
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
            x-data="{ pastedProductData: @js($pastedProductData) }"
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
                <label for="pasted-product-data" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Paste product data
                </label>
                <textarea
                    id="pasted-product-data"
                    x-model="pastedProductData"
                    wire:model="pastedProductData"
                    rows="10"
                    class="mt-2 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm font-mono text-gray-900 dark:text-white placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                ></textarea>
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

    {{-- Revisions Modal --}}
    @if($revisionsModalOpen)
    <div
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
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">Rev {{ $revision->revision_number }}</span>
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
                            &middot; {{ $revision->created_at->format('d M Y, H:i') }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if(!$isViewing)
                        <button
                            wire:click="setActiveRevision({{ $revision->id }})"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                        >
                            View{{ !$isActive ? ' and Set Active' : '' }}
                        </button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
                <button
                    wire:click="createNewRevision"
                    wire:loading.attr="disabled"
                    class="w-full flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 transition-colors"
                >
                    <x-heroicon-o-plus class="w-4 h-4" />
                    <span wire:loading.remove wire:target="createNewRevision">Create New Revision</span>
                    <span wire:loading wire:target="createNewRevision">Creating...</span>
                </button>
                <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">
                    Copies all areas and lines from the current revision into a new one.
                </p>
            </div>
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

</div>
</x-filament-panels::page>
