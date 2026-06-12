<x-filament-panels::page>
    @php
        $validationPassed = $this->validationPassed();
        $quoteApproved = $this->quoteApproved();
        $validationStatus = $this->validationStatusLabel();
        $validationStatusText = $validationPassed ? 'Valid' : 'Not valid';
        $canProduceUnpricedSchedule = $this->canProduceUnpricedSchedule();
        $canProducePricedSchedule = $this->canProducePricedSchedule();
        $canProduceQuote = $this->canProduceQuote();
    @endphp

    <div class="space-y-6">
        @if($canProduceQuote || $canProducePricedSchedule)
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Quote Approval</h2>
                    <div class="mt-2 inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        @if ($quoteApproved)
                            <x-heroicon-o-check-circle class="h-4 w-4 text-success-500" />
                            Quote Approved
                        @else
                            <x-heroicon-o-clock class="h-4 w-4" />
                            Approval Not Requested
                        @endif
                    </div>
                </div>

                @unless ($validationPassed)
                    <div class="flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/60 dark:bg-amber-500/15 dark:text-amber-100">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Validation must pass before requesting approval. Current status:</span>
                        <span class="inline-flex items-center rounded-md bg-amber-200 px-2 py-0.5 text-xs font-semibold text-amber-950 dark:bg-amber-400/25 dark:text-amber-100">{{ $validationStatusText }}</span>
                    </div>
                @endunless

                <div class="flex items-start gap-2 rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-500/60 dark:bg-sky-500/15 dark:text-sky-100">
                    <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Quote PDF requires <strong>validation passed</strong> + <strong>quote approved</strong>. Unpriced outputs are always available. Priced CSV requires <strong>validation passed</strong>.</span>
                </div>
            </div>
            </section>
        @endif

        <div @class([
            'grid gap-5',
            'lg:grid-cols-3' => $canProduceQuote && $canProducePricedSchedule,
            'lg:grid-cols-2' => ($canProduceQuote xor $canProducePricedSchedule),
        ])>
            @if($canProduceQuote)
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <x-heroicon-o-document-text class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Quote PDF</h2>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Priced quote with branding, cover percentage, totals and approval.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    @unless ($validationPassed && $quoteApproved)
                        <div class="flex items-start gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                            <x-heroicon-o-lock-closed class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                @unless ($validationPassed)
                                    Validation must pass first (currently:
                                    <span class="inline-flex items-center rounded-md bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $validationStatusText }}</span>)
                                @else
                                    Quote approval required before generating.
                                @endunless
                            </span>
                        </div>
                    @endunless

                    @if ($validationPassed && $quoteApproved)
                        <a href="{{ $this->getQuotePdfUrl() }}" target="_blank" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md bg-primary-600 px-3 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500">
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Quote PDF
                        </a>
                    @else
                        <button type="button" disabled class="inline-flex h-9 w-full cursor-not-allowed items-center justify-center gap-2 rounded-md bg-gray-300 px-3 text-sm font-semibold text-gray-600 dark:bg-white/20 dark:text-gray-400">
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Quote PDF
                        </button>
                    @endif
                </div>
                </section>
            @endif

            @if($canProducePricedSchedule)
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <x-heroicon-o-table-cells class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Priced Schedule</h2>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Schedule export with pricing. Requires validation passed.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    @unless ($validationPassed)
                        <div class="flex items-start gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                            <x-heroicon-o-lock-closed class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                Validation must pass (currently:
                                <span class="inline-flex items-center rounded-md bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $validationStatusText }}</span>)
                            </span>
                        </div>
                    @endunless

                    @if ($validationPassed)
                        <a href="{{ $this->getCsvExportUrl() }}" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-gray-200 px-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                            <x-heroicon-o-table-cells class="h-4 w-4" />
                            Download Priced CSV
                        </a>
                    @else
                        <button type="button" disabled class="inline-flex h-9 w-full cursor-not-allowed items-center justify-center gap-2 rounded-md border border-gray-200 px-3 text-sm font-semibold text-gray-400 dark:border-white/10 dark:text-gray-500">
                            <x-heroicon-o-table-cells class="h-4 w-4" />
                            Download Priced CSV
                        </button>
                    @endif
                </div>
                </section>
            @endif

            @if($canProduceUnpricedSchedule)
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <x-heroicon-o-clipboard-document-list class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Unpriced Schedule</h2>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Schedule without pricing. Always available.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <a href="{{ $this->getSchedulePdfUrl() }}" target="_blank" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-gray-200 px-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                        <x-heroicon-o-clipboard-document-list class="h-4 w-4" />
                        Generate Unpriced PDF
                    </a>
                    <a href="{{ $this->getUnpricedCsvExportUrl() }}" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-gray-200 px-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                        <x-heroicon-o-table-cells class="h-4 w-4" />
                        Unpriced CSV
                    </a>
                </div>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
