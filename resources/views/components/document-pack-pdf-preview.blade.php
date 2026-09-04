<div
    x-data="{
        previewUrl: null,
        previewFilename: 'PDF preview',
    }"
    x-on:open-document-pack-pdf-preview.window="
        previewUrl = $event.detail?.url ?? null;
        previewFilename = $event.detail?.filename || 'PDF preview';

        if (previewUrl) {
            $dispatch('open-modal', { id: 'document-pack-pdf-preview' });
        }
    "
    x-on:close-modal.window="
        if ($event.detail?.id === 'document-pack-pdf-preview') {
            previewUrl = null;
        }
    "
>
    <x-filament::modal
        id="document-pack-pdf-preview"
        width="screen-xl"
        :autofocus="false"
    >
        <x-slot name="heading">
            <span x-text="previewFilename">PDF preview</span>
        </x-slot>

        <template x-if="previewUrl">
            <div class="h-[72vh] overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10">
                <iframe
                    class="h-full w-full border-0 bg-white"
                    x-bind:src="previewUrl"
                    x-bind:title="`Preview of ${previewFilename}`"
                ></iframe>
            </div>
        </template>
    </x-filament::modal>
</div>
