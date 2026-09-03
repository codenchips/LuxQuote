@if ($resourceFile->isBrowserPreviewable())
    <div class="h-[72vh] overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10">
        <iframe
            class="h-full w-full border-0 bg-white"
            src="{{ $previewUrl }}"
            title="Preview of {{ $resourceFile->display_name }}"
        ></iframe>
    </div>
@else
    <div class="flex min-h-80 flex-col items-center justify-center gap-4 rounded-xl border border-gray-200 bg-gray-50 p-8 text-center dark:border-white/10 dark:bg-white/5">
        <x-filament::icon
            icon="heroicon-o-document"
            class="h-12 w-12 text-gray-400"
        />

        <div>
            <p class="font-semibold text-gray-950 dark:text-white">Preview unavailable</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                This file type cannot be displayed directly by your browser.
            </p>
        </div>

        <x-filament::button
            tag="a"
            href="{{ $previewUrl }}"
            icon="heroicon-o-arrow-down-tray"
            download
        >
            Download file
        </x-filament::button>
    </div>
@endif
