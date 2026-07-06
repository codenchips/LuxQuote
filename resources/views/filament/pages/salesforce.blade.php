<x-filament-panels::page>
    <div class="mb-4 flex justify-end">
        <div class="flex items-center gap-3 rounded-lg border border-gray-800 bg-gray-900/60 px-4 py-3">
            <div class="text-right">
                <div class="text-sm font-semibold text-white">Salesforce push</div>
                <div class="text-xs text-gray-400">
                    {{ $salesforcePushDisabled ? 'Outbound writes paused' : 'Outbound writes enabled' }}
                </div>
            </div>

            <button
                type="button"
                role="switch"
                aria-checked="{{ $salesforcePushDisabled ? 'false' : 'true' }}"
                @disabled(! $this->canManageSalesforcePush())
                wire:click="toggleSalesforcePushDisabled"
                @class([
                    'relative inline-flex h-7 w-12 items-center rounded-full transition focus:outline-none focus:ring-2 focus:ring-warning-500 focus:ring-offset-2 focus:ring-offset-gray-950',
                    'bg-emerald-600' => ! $salesforcePushDisabled,
                    'bg-amber-600' => $salesforcePushDisabled,
                    'cursor-not-allowed opacity-60' => ! $this->canManageSalesforcePush(),
                ])
            >
                <span
                    @class([
                        'inline-block h-5 w-5 rounded-full bg-white shadow transition',
                        'translate-x-6' => ! $salesforcePushDisabled,
                        'translate-x-1' => $salesforcePushDisabled,
                    ])
                ></span>
            </button>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
