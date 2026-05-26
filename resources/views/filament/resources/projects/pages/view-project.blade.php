<x-filament-panels::page>
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
                        wire:click="addBlankLine({{ $area->id }})"
                        class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300"
                    >
                        <x-heroicon-o-plus class="w-3 h-3" /> Blank Row
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
                    style="grid-template-columns: 20px 110px 1fr 60px 90px 95px 1fr 70px 60px"
                >
                    <div></div>
                    <div>Code</div>
                    <div>Description</div>
                    <div class="text-right">Qty</div>
                    <div>Type</div>
                    <div class="text-right">Unit Price</div>
                    <div>Notes</div>
                    <div>Status</div>
                    <div></div>
                </div>

                {{-- Sortable lines --}}
                <div
                    x-sort="(id, pos) => $wire.sortLine(parseInt(id), pos, {{ $area->id }})"
                    x-sort:config="{ group: 'projectLines', animation: 150 }"
                    class="divide-y divide-gray-100 dark:divide-gray-800 border-t border-gray-100 dark:border-gray-800"
                >
                    @foreach($area->lines as $line)
                    <div
                        wire:key="line-{{ $line->id }}"
                        x-sort:item="{{ $line->id }}"
                        class="grid items-center gap-2 px-4 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $line->type === \App\Enums\ProjectLineType::Temp ? 'bg-amber-50/60 dark:bg-amber-900/10' : '' }}"
                        style="grid-template-columns: 20px 110px 1fr 60px 90px 95px 1fr 70px 60px"
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
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'code', $el.value)"
                            placeholder="–"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm font-mono hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white {{ $line->type === \App\Enums\ProjectLineType::Temp ? 'text-amber-600 dark:text-amber-400' : '' }}"
                        />

                        {{-- Description --}}
                        <input
                            value="{{ $line->description }}"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'description', $el.value)"
                            placeholder="Description..."
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Qty --}}
                        <input
                            type="number"
                            value="{{ $line->qty }}"
                            min="1"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'qty', parseInt($el.value) || 1)"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Type badge --}}
                        <span class="inline-flex items-center justify-center rounded px-2 py-0.5 text-xs font-semibold {{ $line->type === \App\Enums\ProjectLineType::Temp ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                            {{ $line->type->label() }}
                        </span>

                        {{-- Unit Price --}}
                        <input
                            type="number"
                            step="0.01"
                            value="{{ $line->unit_price }}"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'unit_price', $el.value)"
                            placeholder="0.00"
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm text-right hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-900 dark:text-white"
                        />

                        {{-- Notes --}}
                        <input
                            value="{{ $line->notes }}"
                            x-on:blur="$wire.updateLineField({{ $line->id }}, 'notes', $el.value)"
                            placeholder="Notes..."
                            class="w-full rounded border border-transparent bg-transparent px-2 py-1 text-sm hover:border-gray-300 dark:hover:border-gray-600 focus:border-primary-500 focus:outline-none text-gray-500 dark:text-gray-400"
                        />

                        {{-- Status --}}
                        <span class="text-sm text-gray-400 text-center">–</span>

                        {{-- Row actions --}}
                        <div class="flex items-center justify-end gap-0.5">
                            <button
                                wire:click="duplicateLine({{ $line->id }})"
                                title="Duplicate row"
                                class="rounded p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                            >
                                <x-heroicon-o-document-duplicate class="w-4 h-4" />
                            </button>
                            <button
                                wire:click="deleteLine({{ $line->id }})"
                                wire:confirm="Delete this line?"
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
                        No items in this area.
                        <button
                            wire:click="addBlankLine({{ $area->id }})"
                            class="ml-1 text-primary-500 hover:underline"
                        >
                            Add a blank row
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
</x-filament-panels::page>
