<x-filament-panels::page>
    @include('filament.pages.actions.salesforce-push-control', [
        'canManageSalesforcePush' => true,
        'salesforcePushDisabled' => $salesforcePushDisabled,
    ])
    <section class="rounded-lg border border-gray-700 p-5 space-y-4">
        <h2 class="text-lg font-semibold">Scheduled maintenance</h2>
        <p class="text-sm text-gray-400">Users will be signed out at the start. Access resumes at the return time or after a successful deployment, whichever comes first.</p>
        <div class="flex flex-wrap gap-4">
            <label class="block min-w-56 space-y-2 text-sm font-medium">
                <span>Start</span>
                <x-filament::input.wrapper><x-filament::input type="datetime-local" wire:model.live="maintenanceStart" /></x-filament::input.wrapper>
            </label>
            <label class="block min-w-56 space-y-2 text-sm font-medium">
                <span>Return time</span>
                <x-filament::input.wrapper><x-filament::input type="datetime-local" wire:model="maintenanceEnd" /></x-filament::input.wrapper>
            </label>
        </div>
        @error('maintenanceStart') <p class="text-red-400">{{ $message }}</p> @enderror
        @error('maintenanceEnd') <p class="text-red-400">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button type="button" class="lux-tools-primary-button" wire:click="scheduleMaintenance">{{ $maintenanceScheduled ? 'Update schedule' : 'Schedule maintenance' }}</x-filament::button>
            @if ($maintenanceScheduled)
                <x-filament::button type="button" color="gray" wire:click="cancelMaintenance">Cancel schedule</x-filament::button>
            @endif
        </div>
    </section>
    <section class="rounded-lg border border-gray-700 p-5 space-y-4">
        <h2 class="text-lg font-semibold">Database backup</h2>
        <p class="text-sm text-gray-400">The export contains the complete database and sensitive information. Uploaded files are not included.</p>
        <x-filament::button tag="a" class="lux-tools-primary-button" :href="route('admin.tools.database-export')">Export database</x-filament::button>
        <div class="border-t border-gray-700 pt-5">
            <h3 class="text-base font-semibold">Import database</h3>
            <p class="mt-2 text-sm text-gray-400">This replaces the current database. A fresh database backup is saved first; uploaded files are not restored. Only complete SQL dumps containing the current set of tables are accepted. Import only trusted files: SQL can contain database commands.</p>
            <form method="POST" action="{{ route('admin.tools.database-import') }}" enctype="multipart/form-data" class="mt-4 space-y-4" onsubmit="if (!confirm('Replace the current database? This cannot be undone without restoring a backup.')) return false; this.querySelector('button[type=submit]').disabled = true; this.querySelector('[data-import-progress]').hidden = false; return true;">
                @csrf
                <label class="block max-w-lg space-y-2 text-sm font-medium">
                    <span>SQL backup file (maximum 90 MB)</span>
                    <x-filament::input.wrapper><x-filament::input type="file" name="dump" accept=".sql" required /></x-filament::input.wrapper>
                </label>
                @error('dump') <p class="text-sm text-danger-400">{{ $message }}</p> @enderror
                <label class="block max-w-lg space-y-2 text-sm font-medium">
                    <span>Type RESTORE {{ DB::connection()->getDatabaseName() }} to confirm</span>
                    <x-filament::input.wrapper><x-filament::input name="confirmation" autocomplete="off" required /></x-filament::input.wrapper>
                </label>
                @error('confirmation') <p class="text-sm text-danger-400">{{ $message }}</p> @enderror
                <x-filament::button type="submit" class="lux-tools-primary-button">Import database</x-filament::button>
                <p hidden data-import-progress class="text-sm text-gray-400">Importing. Keep this page open; if the connection drops, check the server before trying again.</p>
            </form>
        </div>
    </section>
</x-filament-panels::page>
