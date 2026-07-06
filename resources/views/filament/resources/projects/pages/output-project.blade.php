<x-filament-panels::page>
    @php
        $validationPassed = $this->validationPassed();
        $quoteApproved = $this->quoteApproved();
        $validationStatusText = $validationPassed ? 'Passed' : 'Not passed';
        $approvalRequested = $this->quoteApprovalRequested();
        $approvalStatusLabel = $quoteApproved ? 'Approved' : ($approvalRequested ? 'Requested' : 'Approval Required');
        $approvalStatusClasses = $quoteApproved
            ? 'border-success-500/30 bg-success-500/15 text-success-200'
            : ($approvalRequested
                ? 'border-amber-500/35 bg-amber-500/20 text-amber-100'
                : 'border-amber-500/35 bg-amber-500/20 text-amber-100');
        $canProduceUnpricedSchedule = $this->canProduceUnpricedSchedule();
        $canProducePricedSchedule = $this->canProducePricedSchedule();
        $canProduceQuote = $this->canProduceQuote();
        $canRequestQuoteApproval = $this->canRequestQuoteApproval();
        $canViewValidation = $this->canViewValidation();
        $canManageDocumentPacks = $this->canManageDocumentPacks();
        $includeQuoteDatasheets = $this->includeQuoteDatasheets;
        $includeScheduleDatasheets = $this->includeScheduleDatasheets;
        $documentPackDownloadUrl = $this->getDocumentPackDownloadUrl();
        $documentPackGenerationBlockReason = $this->documentPackGenerationBlockReason();
        $selectedGenerationRevision = $this->selectedGenerationRevision();
        $panelClasses = 'rounded-lg border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#171b22]';
        $primaryButtonClasses = 'fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action w-full';
        $secondaryButtonClasses = 'inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-gray-200 bg-white/70 px-4 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-100 dark:hover:bg-white/[0.06]';
        $disabledOutputButtonClasses = 'inline-flex h-10 w-full cursor-not-allowed items-center justify-center gap-2 rounded-md bg-gray-200 px-4 text-sm font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-500';
        $validationUrl = \App\Filament\Resources\Projects\Pages\ValidationProject::getUrl(['record' => $this->record]);
    @endphp

    <div class="space-y-7">
        <section class="{{ $panelClasses }} p-5">
            <div class="grid gap-5 lg:grid-cols-[1fr_1fr_1fr]">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Quote status</h2>
                        <span class="inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold {{ $approvalStatusClasses }}">
                            {{ $approvalStatusLabel }}
                        </span>
                    </div>
                    <p class="max-w-md text-sm text-gray-600 dark:text-gray-300">
                        @if($quoteApproved)
                            This quote is approved and ready for controlled outputs.
                        @elseif($approvalRequested)
                            Approval has been requested. A quote cannot be generated until it has been approved.
                        @else
                            A quote cannot be generated until it has been approved.
                        @endif
                    </p>

                    @if($canRequestQuoteApproval && ! $quoteApproved)
                        <button
                            type="button"
                            wire:click="requestQuoteApproval"
                            class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                        >
                            <x-heroicon-o-paper-airplane class="h-4 w-4" />
                            Request Approval
                        </button>
                    @endif
                </div>

                @if($canViewValidation)
                    <div class="space-y-5 border-t border-gray-200 pt-5 dark:border-white/10 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                        <div class="space-y-5">
                            <div class="flex items-center gap-3">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Validation</h2>
                                <span @class([
                                    'inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold',
                                    'border-success-500/30 bg-success-500/15 text-success-200' => $validationPassed,
                                    'border-amber-500/35 bg-amber-500/20 text-amber-100' => ! $validationPassed,
                                ])>
                                    {{ $validationStatusText }}
                                </span>
                            </div>
                            <p class="max-w-md text-sm text-gray-600 dark:text-gray-300">
                                @if($validationPassed)
                                    All validations passed. You can generate outputs.
                                @else
                                    Validation must pass before outputs can be generated.
                                @endif
                            </p>
                        </div>

                        <a href="{{ $validationUrl }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-gray-200 bg-white/70 px-4 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:bg-white/[0.04] dark:text-gray-100 dark:hover:bg-white/[0.08]">
                            <x-heroicon-o-clock class="h-4 w-4" />
                            View Validation
                        </a>
                    </div>
                @endif

                <div @class([
                    'rounded-lg border border-gray-200 bg-gray-50/80 p-5 dark:border-white/10 dark:bg-white/[0.03]',
                    'lg:col-start-3' => $canViewValidation,
                    'lg:col-span-2' => ! $canViewValidation,
                ])>
                    <div class="flex items-start gap-4">
                        <x-heroicon-o-information-circle class="mt-0.5 h-6 w-6 shrink-0 text-info-500" />
                        <div class="space-y-2 text-sm">
                            <p class="font-medium text-gray-950 dark:text-white">All outputs include links to product datasheets.</p>
                            <p class="text-gray-600 dark:text-gray-300">
                                Include datasheets in your PDF if you need a self-contained document.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div role="tablist" aria-label="Output type" class="border-b border-gray-200 dark:border-white/10">
            <div class="flex gap-7">
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('outputTab', 'single')"
                    aria-selected="{{ $outputTab === 'single' ? 'true' : 'false' }}"
                    @class([
                        'relative -mb-px inline-flex h-11 items-center text-sm font-semibold transition',
                        'border-b-2 border-orange-500 text-gray-950 dark:border-orange-400 dark:text-white' => $outputTab === 'single',
                        'border-b-2 border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $outputTab !== 'single',
                    ])
                >
                    Quick Output
                </button>

                @if($canManageDocumentPacks)
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('outputTab', 'packs')"
                        aria-selected="{{ $outputTab === 'packs' ? 'true' : 'false' }}"
                        @class([
                            'relative -mb-px inline-flex h-11 items-center text-sm font-semibold transition',
                            'border-b-2 border-orange-500 text-gray-950 dark:border-orange-400 dark:text-white' => $outputTab === 'packs',
                            'border-b-2 border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $outputTab !== 'packs',
                        ])
                    >
                        Document Packs
                    </button>
                @endif
            </div>
        </div>

        @if($outputTab === 'packs' && $canManageDocumentPacks)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Document Packs</h2>
                        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                            Build a reusable pack, drag documents into the required order, then generate it for any project revision.
                        </p>
                    </div>

                    <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                        @if($this->documentPacks->isNotEmpty())
                            <select
                                wire:model="selectedDocumentPackId"
                                wire:change="loadDocumentPack($event.target.value)"
                                class="h-10 min-w-56 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            >
                                @foreach($this->documentPacks as $pack)
                                    <option value="{{ $pack->id }}">{{ $pack->name }} ({{ $pack->items_count }})</option>
                                @endforeach
                            </select>
                        @endif

                        <button
                            type="button"
                            wire:click="newDocumentPack"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            <x-heroicon-o-plus class="h-4 w-4" />
                            New Pack
                        </button>
                    </div>
                </div>

                <div class="mt-5 flex items-center gap-3 rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-900 dark:border-sky-500/50 dark:bg-sky-500/15 dark:text-sky-100">
                    <x-heroicon-o-information-circle class="h-5 w-5 shrink-0" />
                    <span>This is work in progress and will include pack selection and templated documents.</span>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Pack name</span>
                        <input
                            type="text"
                            wire:model="documentPackName"
                            wire:input="markDocumentPackDirty"
                            placeholder="e.g. Customer Quote Pack"
                            class="mt-1 block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        />
                        @error('documentPackName')
                            <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Generate using revision</span>
                        <select
                            wire:model.live="generationRevisionId"
                            class="mt-1 block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                            @foreach($this->projectRevisions as $revision)
                                <option value="{{ $revision->id }}">{{ $revision->label() }}{{ $revision->id === $this->record->active_revision_id ? ' — Active' : '' }}</option>
                            @endforeach
                        </select>
                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                            Generated quote and schedule items use this revision. Uploaded PDFs remain project-level.
                        </span>
                    </label>
                </div>

                @error('documentPackItems')
                    <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">{{ $message }}</div>
                @enderror

                <div
                    class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-6"
                    x-sort="(key, position) => $wire.sortDocumentPackItem(key, position)"
                    x-sort:config="{ animation: 150 }"
                >
                    @foreach($documentPackItems as $itemKey => $item)
                        @php
                            $role = \App\Enums\DocumentPackItemRole::tryFrom($item['role']);
                            $roleLabel = $role?->label();
                            $requiresUpload = $this->documentPackRoleRequiresUpload($item['role']);
                            $sourceLabel = $role?->source() === \App\Enums\DocumentPackItemSource::Template ? 'Template' : 'Generated';
                            $hasReplacementUpload = $this->documentPackItemHasActiveUpload($item);
                            $hasExistingFile = $this->documentPackItemHasVisibleExistingFile($item);
                            $hasFile = $hasExistingFile || $hasReplacementUpload;
                            $uploadedFile = $documentPackUploads[$itemKey] ?? null;
                            $uploadedOriginalName = $documentPackUploadOriginalNames[$itemKey] ?? null;
                            $displayFilename = $hasReplacementUpload && $uploadedFile ? ($uploadedOriginalName ?? $uploadedFile->getClientOriginalName()) : $item['original_filename'];
                            $pdfPreviewUrl = $requiresUpload && $hasFile ? $this->documentPackItemPdfUrl($item) : null;
                            $isEmpty = blank($item['role']) || ($requiresUpload && ! $hasFile);
                        @endphp
                        <article
                            wire:key="document-pack-item-{{ $itemKey }}"
                            x-data="{
                                previewKey: @js($itemKey),
                                previewUrl: window.documentPackPreviewUrls?.[@js($itemKey)] ?? null,
                                selectedFilename: window.documentPackPreviewFilenames?.[@js($itemKey)] ?? @js($displayFilename),
                                uploadError: null,
                                uploading: false,
                                acceptsPdf(file) {
                                    if (! file) {
                                        return false;
                                    }

                                    return file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
                                },
                                rejectSelectedFile(event) {
                                    const file = event.target.files?.[0] ?? null;

                                    if (! file || this.acceptsPdf(file)) {
                                        return;
                                    }

                                    event.preventDefault();
                                    event.stopImmediatePropagation();
                                    event.target.value = '';
                                    this.uploadError = 'Only PDF files can be uploaded.';
                                },
                                setSelectedFile(file) {
                                    if (! file) {
                                        return;
                                    }

                                    if (! this.acceptsPdf(file)) {
                                        this.uploadError = 'Only PDF files can be uploaded.';

                                        return;
                                    }

                                    this.uploadError = null;

                                    if (this.$refs.fileInput) {
                                        this.$refs.fileInput.value = '';
                                    }

                                    window.documentPackPreviewUrls ??= {};
                                    window.documentPackPreviewFilenames ??= {};

                                    this.selectedFilename = file.name;
                                    window.documentPackPreviewFilenames[this.previewKey] = this.selectedFilename;
                                    this.uploading = true;

                                    const reader = new FileReader();
                                    reader.onload = () => {
                                        this.previewUrl = reader.result;
                                        window.documentPackPreviewUrls[this.previewKey] = this.previewUrl;
                                    };
                                    reader.readAsDataURL(file);

                                    const finishUpload = () => {
                                        $wire.set('documentPackUploadOriginalNames.{{ $itemKey }}', file.name);
                                        $wire.call('markDocumentPackDirty');
                                    };

                                    $wire.call('clearDocumentPackUpload', this.previewKey).then(() => {
                                        $wire.upload('documentPackUploads.{{ $itemKey }}', file,
                                            () => {
                                                this.uploading = false;
                                                finishUpload();
                                            },
                                            () => {
                                                this.uploading = false;
                                                this.uploadError = 'The PDF could not be uploaded.';
                                            },
                                        );
                                    });
                                },
                                chooseFile(event) {
                                    this.setSelectedFile(event.target.files?.[0] ?? null);
                                },
                                dropFile(event) {
                                    const file = event.dataTransfer.files?.[0] ?? null;

                                    if (! file) {
                                        return;
                                    }

                                    if (! this.acceptsPdf(file)) {
                                        this.uploadError = 'Only PDF files can be uploaded.';

                                        return;
                                    }

                                    this.setSelectedFile(file);
                                },
                            }"
                            x-sort:item="'{{ $itemKey }}'"
                            @class([
                                'relative min-h-64 rounded-xl border-2 bg-white p-4 transition dark:bg-gray-950/40',
                                'border-dashed border-gray-300 dark:border-gray-600' => $isEmpty,
                                'border-solid border-primary-200 shadow-sm dark:border-primary-500/30' => ! $isEmpty,
                            ])
                        >
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    x-sort:handle
                                    class="cursor-grab rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 active:cursor-grabbing dark:hover:bg-white/10 dark:hover:text-gray-200"
                                    title="Drag to reorder"
                                >
                                    <x-heroicon-o-bars-3 class="h-5 w-5" />
                                </button>
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Document {{ $loop->iteration }}</span>
                                <div class="flex-1"></div>
                                <button
                                    type="button"
                                    wire:click="removeDocumentPackItem('{{ $itemKey }}')"
                                    class="rounded-md p-1 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-500/10"
                                    title="Remove document"
                                >
                                    <x-heroicon-o-x-mark class="h-5 w-5" />
                                </button>
                            </div>

                            @if(blank($item['role']))
                                <div class="mt-3">
                                    <select
                                        wire:key="document-pack-role-{{ $itemKey }}"
                                        wire:model.live="documentPackItems.{{ $itemKey }}.role"
                                        wire:change="markDocumentPackDirty"
                                        class="block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    >
                                        <option value="">Select a document...</option>
                                        @foreach($this->documentPackRoleOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('documentPackItems.'.$itemKey.'.role')
                                        <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>
                                    @enderror
                                </div>
                            @elseif(filled($roleLabel))
                                <div class="mt-3 text-center text-sm font-semibold text-gray-900 dark:text-white">{{ $roleLabel }}</div>
                            @endif

                            @if($requiresUpload)
                                @if($hasFile)
                                    <a
                                        x-show="previewUrl || @js((bool) $pdfPreviewUrl)"
                                        x-bind:href="previewUrl || @js($pdfPreviewUrl)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mx-auto mt-3 block w-[165px] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition hover:border-primary-400 hover:ring-2 hover:ring-primary-500/20 dark:border-white/10 dark:bg-gray-900"
                                        title="Open uploaded PDF in a new tab"
                                    >
                                        <div class="h-[233px] w-[165px] overflow-hidden bg-gray-100 dark:bg-gray-800">
                                            <iframe
                                                x-bind:src="(previewUrl || @js($pdfPreviewUrl)) ? (previewUrl || @js($pdfPreviewUrl)) + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH' : null"
                                                title="Preview of uploaded PDF"
                                                scrolling="no"
                                                class="pointer-events-none h-[253px] w-[185px] max-w-none overflow-hidden border-0"
                                            ></iframe>
                                        </div>
                                    </a>
                                    <div x-show="! previewUrl && ! @js((bool) $pdfPreviewUrl)" class="mx-auto mt-3 flex h-[233px] w-[165px] flex-col items-center justify-center rounded-lg border border-gray-200 bg-gray-50 px-3 text-center dark:border-white/10 dark:bg-white/5">
                                        <x-heroicon-o-document-check class="h-8 w-8 text-primary-500" />
                                        <span class="mt-2 text-xs font-semibold text-gray-500 dark:text-gray-400">PDF selected</span>
                                    </div>
                                    <div class="mx-auto mt-2 max-w-[165px] text-center">
                                        <div x-text="selectedFilename" class="truncate text-xs text-gray-500 dark:text-gray-400"></div>
                                        <label for="document-pack-upload-{{ $itemKey }}" class="mt-1 inline-flex cursor-pointer text-xs font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">replace</label>
                                        <input
                                            id="document-pack-upload-{{ $itemKey }}"
                                            x-ref="fileInput"
                                            x-on:change.capture="rejectSelectedFile($event)"
                                            x-on:click="$event.target.value = ''"
                                            x-on:change="chooseFile($event)"
                                            type="file"
                                            accept="application/pdf,.pdf"
                                            class="sr-only"
                                        />
                                    </div>
                                @else
                                    <label
                                        x-on:dragenter.prevent.stop
                                        x-on:dragover.prevent.stop
                                        x-on:drop.prevent.stop="dropFile($event)"
                                        class="mx-auto mt-3 flex h-[233px] w-[165px] cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-center transition hover:border-primary-400 hover:bg-primary-50/50 dark:border-gray-600 dark:bg-white/5 dark:hover:border-primary-500 dark:hover:bg-primary-500/5"
                                    >
                                        <template x-if="previewUrl">
                                            <div class="h-[233px] w-[165px] overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                                                <iframe
                                                    x-bind:src="previewUrl + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH'"
                                                    title="Preview of selected PDF"
                                                    scrolling="no"
                                                    class="pointer-events-none h-[253px] w-[185px] max-w-none overflow-hidden border-0"
                                                ></iframe>
                                            </div>
                                        </template>
                                        <div x-show="! previewUrl" class="flex flex-col items-center justify-center px-3">
                                            <x-heroicon-o-document class="h-8 w-8 text-gray-400" />
                                            <span class="mt-2 text-xs font-semibold text-gray-500 dark:text-gray-400">Upload PDF</span>
                                            <span class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">Drop a file here or click to choose</span>
                                        </div>
                                        <input
                                            x-ref="fileInput"
                                            x-on:change.capture="rejectSelectedFile($event)"
                                            x-on:click="$event.target.value = ''"
                                            x-on:change="chooseFile($event)"
                                            type="file"
                                            accept="application/pdf,.pdf"
                                            class="sr-only"
                                        />
                                    </label>
                                @endif

                                <div x-show="uploadError" x-text="uploadError" class="mt-2 text-xs text-danger-600"></div>
                                <div x-show="uploading" class="mt-2 text-xs text-primary-600">Uploading PDF...</div>
                                @error('documentPackUploads.'.$itemKey)
                                    <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>
                                @enderror
                            @elseif(filled($item['role']))
                                <div class="mx-auto mt-3 flex h-[233px] w-[165px] flex-col items-center justify-center rounded-lg border border-primary-200 bg-primary-50 px-3 text-center dark:border-primary-500/20 dark:bg-primary-500/10">
                                    <x-heroicon-o-sparkles class="h-8 w-8 text-primary-500" />
                                    <span class="mt-2 text-xs font-semibold text-primary-700 dark:text-primary-300">{{ $sourceLabel }}</span>
                                    @if($item['role'] === \App\Enums\DocumentPackItemRole::Quote->value && (! $selectedGenerationRevision?->validated || $selectedGenerationRevision?->status !== \App\Enums\ProjectRevisionStatus::Approved))
                                        <span class="mt-2 text-xs text-amber-600 dark:text-amber-400">Quote not approved</span>
                                    @endif
                                </div>
                                @if($this->documentPackGeneratedSummary($item['role']))
                                    <div class="mx-auto mt-2 max-w-[165px] text-center text-xs text-gray-500 dark:text-gray-400">{{ $this->documentPackGeneratedSummary($item['role']) }}</div>
                                    @if($this->documentPackGeneratedModifiedAt($item['role']))
                                        <div class="mx-auto mt-1 max-w-[165px] text-center text-[11px] text-gray-400 dark:text-gray-500">Last modified {{ $this->documentPackGeneratedModifiedAt($item['role']) }}</div>
                                    @endif
                                @endif
                            @endif
                        </article>
                    @endforeach

                    <button
                        type="button"
                        wire:click="addDocumentPackItem"
                        class="flex min-h-64 items-center justify-center rounded-xl border-2 border-dashed border-gray-300 text-gray-500 transition hover:border-primary-400 hover:bg-primary-50/40 hover:text-primary-600 dark:border-gray-600 dark:hover:bg-primary-500/5"
                        title="Add document"
                        aria-label="Add document"
                    >
                        <x-heroicon-o-plus class="h-9 w-9" />
                    </button>
                </div>

                <div class="mt-5 flex flex-col gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-end dark:border-white/10">
                    @if($selectedDocumentPackId)
                        <button
                            type="button"
                            wire:click="deleteDocumentPack"
                            wire:confirm="Delete this document pack and its uploaded PDFs?"
                            class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 text-sm font-semibold text-danger-600 transition hover:bg-danger-50 dark:hover:bg-danger-500/10 sm:w-56"
                        >
                            <x-heroicon-o-trash class="h-4 w-4" />
                            Delete Pack
                        </button>
                    @endif

                    @if($documentPackGenerationBlockReason)
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $documentPackGenerationBlockReason }}</span>
                    @endif

                    <button
                        type="button"
                        wire:click="saveDocumentPack"
                        wire:loading.attr="disabled"
                        wire:target="saveDocumentPack"
                        class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-gray-800 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600 sm:w-56"
                    >
                        <x-heroicon-o-bookmark class="h-4 w-4" />
                        Save Pack
                    </button>

                    @if($documentPackDownloadUrl)
                        <a
                            data-testid="generate-document-pack"
                            data-pdf-generation
                            data-pdf-title="Generating document pack"
                            data-pdf-message="Your document pack is being generated. Large packs can take a while."
                            href="{{ $documentPackDownloadUrl }}"
                            class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action h-10 w-full whitespace-nowrap sm:w-56"
                        >
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Combined PDF
                        </a>
                    @else
                        <button data-testid="generate-document-pack" type="button" disabled class="inline-flex h-10 w-full cursor-not-allowed items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-gray-300 px-4 text-sm font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-500 sm:w-56">
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Combined PDF
                        </button>
                    @endif
                </div>
            </section>
        @endif

        @if($outputTab === 'single')
            <section class="space-y-5">
                <div>
                    <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Generate a document</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose the type of document you want to generate.</p>
                </div>

                <div class="grid gap-5 xl:grid-cols-2">
                    @if($canProduceQuote)
                        <article class="{{ $panelClasses }} p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex min-w-0 items-start gap-4">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100 text-gray-500 dark:border-white/10 dark:bg-white/[0.06] dark:text-gray-300">
                                        <x-heroicon-o-document-text class="h-7 w-7" />
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Quote PDF</h3>
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Quote with pricing.</p>
                                    </div>
                                </div>

                                @unless($quoteApproved)
                                    <span class="shrink-0 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 dark:border-white/20 dark:text-gray-300">Requires approval</span>
                                @endunless
                            </div>

                            <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50/80 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                <label class="flex min-h-9 items-center justify-between gap-4 text-sm text-gray-600 dark:text-gray-300">
                                    <span class="inline-flex items-center gap-2">
                                        Include datasheets
                                        <x-heroicon-o-information-circle class="h-4 w-4 text-gray-400" />
                                    </span>
                                    <input type="checkbox" wire:model.live="includeQuoteDatasheets" class="sr-only">
                                    <span @class([
                                        'relative inline-flex h-6 w-11 shrink-0 rounded-full transition',
                                        'bg-orange-500' => $includeQuoteDatasheets,
                                        'bg-gray-300 dark:bg-white/10' => ! $includeQuoteDatasheets,
                                    ]) aria-hidden="true">
                                        <span @class([
                                            'absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition',
                                            'translate-x-5' => $includeQuoteDatasheets,
                                        ])></span>
                                    </span>
                                </label>

                                <div class="mt-4 space-y-3">
                                    @if($validationPassed && $quoteApproved)
                                        <a
                                            href="{{ $this->getQuotePdfUrl() }}"
                                            target="_blank"
                                            data-pdf-generation
                                            data-pdf-title="Generating quote PDF"
                                            data-pdf-message="Your quote PDF is being generated. Including datasheets can take a while."
                                            class="{{ $primaryButtonClasses }}"
                                        >
                                            Generate Quote PDF
                                        </a>
                                    @else
                                        <button type="button" disabled class="{{ $disabledOutputButtonClasses }}">
                                            Generate Quote PDF
                                        </button>
                                    @endif

                                    @if($canProducePricedSchedule)
                                        @if($validationPassed)
                                            <a href="{{ $this->getCsvExportUrl() }}" class="{{ $secondaryButtonClasses }}">
                                                <x-heroicon-o-table-cells class="h-5 w-5" />
                                                Download as CSV
                                            </a>
                                        @else
                                            <button type="button" disabled class="{{ $disabledOutputButtonClasses }}">
                                                <x-heroicon-o-table-cells class="h-5 w-5" />
                                                Download as CSV
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endif

                    @if($canProduceUnpricedSchedule)
                        <article class="{{ $panelClasses }} p-5">
                            <div class="flex items-start gap-4">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100 text-gray-500 dark:border-white/10 dark:bg-white/[0.06] dark:text-gray-300">
                                    <x-heroicon-o-document-text class="h-7 w-7" />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Schedule PDF</h3>
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Schedule without pricing. Always available.</p>
                                </div>
                            </div>

                            <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50/80 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                <label class="flex min-h-9 items-center justify-between gap-4 text-sm text-gray-600 dark:text-gray-300">
                                    <span class="inline-flex items-center gap-2">
                                        Include datasheets
                                        <x-heroicon-o-information-circle class="h-4 w-4 text-gray-400" />
                                    </span>
                                    <input type="checkbox" wire:model.live="includeScheduleDatasheets" class="sr-only">
                                    <span @class([
                                        'relative inline-flex h-6 w-11 shrink-0 rounded-full transition',
                                        'bg-orange-500' => $includeScheduleDatasheets,
                                        'bg-gray-300 dark:bg-white/10' => ! $includeScheduleDatasheets,
                                    ]) aria-hidden="true">
                                        <span @class([
                                            'absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition',
                                            'translate-x-5' => $includeScheduleDatasheets,
                                        ])></span>
                                    </span>
                                </label>

                                <div class="mt-4 space-y-3">
                                    <a
                                        href="{{ $this->getSchedulePdfUrl() }}"
                                        target="_blank"
                                        data-pdf-generation
                                        data-pdf-title="Generating schedule PDF"
                                        data-pdf-message="Your schedule PDF is being generated. Including datasheets can take a while."
                                        class="{{ $primaryButtonClasses }}"
                                    >
                                        Generate Schedule PDF
                                    </a>
                                    <a href="{{ $this->getUnpricedCsvExportUrl() }}" class="{{ $secondaryButtonClasses }}">
                                        <x-heroicon-o-table-cells class="h-5 w-5" />
                                        Download as CSV
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endif
                </div>

                <aside class="{{ $panelClasses }} flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-4">
                        <x-heroicon-o-information-circle class="mt-0.5 h-6 w-6 shrink-0 text-info-500" />
                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">About datasheets</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                Datasheets are included as linked references by default. Including them in the PDF will embed the files, increasing generation time and file size.
                            </p>
                        </div>
                    </div>
                </aside>
            </section>
        @endif
    </div>
</x-filament-panels::page>
