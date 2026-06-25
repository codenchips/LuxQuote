<x-filament-panels::page>
    @php
        $validationPassed = $this->validationPassed();
        $quoteApproved = $this->quoteApproved();
        $validationStatus = $this->validationStatusLabel();
        $validationStatusText = $validationPassed ? 'Valid' : 'Not valid';
        $canProduceUnpricedSchedule = $this->canProduceUnpricedSchedule();
        $canProducePricedSchedule = $this->canProducePricedSchedule();
        $canProduceQuote = $this->canProduceQuote();
        $canManageDocumentPacks = $this->canManageDocumentPacks();
        $documentPackDownloadUrl = $this->getDocumentPackDownloadUrl();
        $documentPackGenerationBlockReason = $this->documentPackGenerationBlockReason();
        $selectedGenerationRevision = $this->selectedGenerationRevision();
        $disabledOutputButtonClasses = 'inline-flex h-9 w-full cursor-not-allowed items-center justify-center gap-2 rounded-md bg-gray-300 px-3 text-sm font-semibold text-gray-600 dark:bg-white/20 dark:text-gray-400';
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

        <div role="tablist" aria-label="Output type">
            <div class="inline-flex gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 dark:border-white/10 dark:bg-gray-900">
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('outputTab', 'single')"
                    aria-selected="{{ $outputTab === 'single' ? 'true' : 'false' }}"
                    @class([
                        'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition',
                        'bg-gray-700 text-white shadow-sm dark:bg-gray-700' => $outputTab === 'single',
                        'bg-gray-200 text-gray-600 hover:bg-gray-300 hover:text-gray-900 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' => $outputTab !== 'single',
                    ])
                >
                    <x-heroicon-o-document-text class="h-4 w-4" />
                    Quick PDF/CSV Output
                </button>

                @if($canManageDocumentPacks)
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('outputTab', 'packs')"
                        aria-selected="{{ $outputTab === 'packs' ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition',
                            'bg-gray-700 text-white shadow-sm dark:bg-gray-700' => $outputTab === 'packs',
                            'bg-gray-200 text-gray-600 hover:bg-gray-300 hover:text-gray-900 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' => $outputTab !== 'packs',
                        ])
                    >
                        <x-heroicon-o-document-duplicate class="h-4 w-4" />
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
                                    <span class="mt-2 text-xs font-semibold text-primary-700 dark:text-primary-300">Generated</span>
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

                <div class="mt-5 flex flex-col gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center dark:border-white/10">
                    @if($selectedDocumentPackId)
                        <button
                            type="button"
                            wire:click="deleteDocumentPack"
                            wire:confirm="Delete this document pack and its uploaded PDFs?"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold text-danger-600 transition hover:bg-danger-50 dark:hover:bg-danger-500/10"
                        >
                            <x-heroicon-o-trash class="h-4 w-4" />
                            Delete Pack
                        </button>
                    @endif

                    <div class="flex-1"></div>

                    @if($documentPackGenerationBlockReason)
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $documentPackGenerationBlockReason }}</span>
                    @endif

                    <button
                        type="button"
                        wire:click="saveDocumentPack"
                        wire:loading.attr="disabled"
                        wire:target="saveDocumentPack"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-gray-800 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600"
                    >
                        <x-heroicon-o-bookmark class="h-4 w-4" />
                        Save Pack
                    </button>

                    @if($documentPackDownloadUrl)
                        <a
                            data-testid="generate-document-pack"
                            href="{{ $documentPackDownloadUrl }}"
                            target="_blank"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500"
                        >
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Combined PDF
                        </a>
                    @else
                        <button data-testid="generate-document-pack" type="button" disabled class="inline-flex h-10 cursor-not-allowed items-center justify-center gap-2 rounded-lg bg-gray-300 px-4 text-sm font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-500">
                            <x-heroicon-o-document-arrow-down class="h-4 w-4" />
                            Generate Combined PDF
                        </button>
                    @endif
                </div>
            </section>
        @endif

        @if($outputTab === 'single')
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
                        <button type="button" disabled class="{{ $disabledOutputButtonClasses }}">
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
                        <button type="button" disabled class="{{ $disabledOutputButtonClasses }}">
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
        @endif
    </div>
</x-filament-panels::page>
